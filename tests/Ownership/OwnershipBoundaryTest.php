<?php

declare(strict_types=1);
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\OptsIntoAdoptedSchemaReuse;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Exceptions\OwnershipException;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization;
use Nvl\Taxonomy\Enums\DeleteTermStrategy;
use Nvl\Taxonomy\Events\TermChanged;
use Nvl\Taxonomy\Exceptions\InvalidParentException;
use Nvl\Taxonomy\Exceptions\TermNotFoundException;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Services\TaxonomyAssignments;
use Nvl\Taxonomy\Services\TaxonomyReader;
use Nvl\Taxonomy\Services\TaxonomyTerms;
use Nvl\Taxonomy\Services\TermModelRegistry;
use Nvl\Taxonomy\Tests\Fixtures\BatchTaxonomyPolicy;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipActor;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipCompany;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipTaxonomyScenario as Scenario;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipTerm;
use Nvl\Taxonomy\Tests\OwnershipTestCase;

uses(OwnershipTestCase::class, OptsIntoAdoptedSchemaReuse::class);
it('isolates catalogue projections inside the admitted native owner', function (): void {
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::firstOrFail();
    $foreign = $runner->run(OwnershipCompany::findOrFail(8), $actor, fn () => Scenario::term('foreign-catalogue'));
    $reader = app(TaxonomyReader::class);

    expect(fn () => $reader->tree('category', SystemActor::Background))->toThrow(OwnershipException::class)
        ->and(fn () => $reader->term('category', $foreign->id, SystemActor::Background))->toThrow(OwnershipException::class);

    $runner->run(OwnershipCompany::findOrFail(7), $actor, function () use ($reader, $foreign): void {
        $local = Scenario::term('local-catalogue');
        $child = Scenario::term('child-catalogue', $local->id);
        $tree = $reader->tree('category', SystemActor::Background, 'en', limit: 2);

        expect($tree->sole()->id)->toBe($local->id)
            ->and($tree->sole()->children[0]->id)->toBe($child->id)
            ->and($reader->term('category', $foreign->id, SystemActor::Background, 'en'))->toBeNull()
            ->and($reader->term('category', $local->id, SystemActor::Background, 'en')->displayName('en'))->toBe('local-catalogue');
    });
});

it('isolates tree moves merges pruning and retained foreign attachments', function (): void {
    $foreign = null;
    $foreignHost = null;
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(8), OwnershipActor::firstOrFail(), function () use (&$foreign, &$foreignHost): void {
        $foreign = Scenario::term();
        $foreignHost = Scenario::host();
        app(TaxonomyAssignments::class)->sync($foreignHost, 'category', [$foreign], SystemActor::Background);
    });
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function () use ($foreign, $foreignHost): void {
        $source = Scenario::term();
        $destination = Scenario::term('destination');
        $child = Scenario::term('child', $source->id);
        $host = Scenario::host();
        app(TaxonomyAssignments::class)->sync($host, 'category', [$source], SystemActor::Background);
        expect(TermModelRegistry::query()->count())->toBe(3)->and(TermModelRegistry::query()->find($foreign->id))->toBeNull();
        $alias = 'laravel_reserved_999';
        $aliased = TermModelRegistry::query()->from($source->getTable().' as '.$alias)->where($alias.'.id', $foreign->id)->orWhereRaw('1=1')->paginate(2);
        expect($aliased->total())->toBe(3)->and($aliased->getCollection()->pluck('id')->all())->not->toContain($foreign->id);
        expect(fn () => app(TaxonomyTerms::class)->move($child, $foreign->id, SystemActor::Background, 0, $child->revision))->toThrow(InvalidParentException::class);
        expect(fn () => app(TaxonomyTerms::class)->merge($source, $foreign, SystemActor::Background, $source->revision, $foreign->revision))->toThrow(TermNotFoundException::class);
        expect(fn () => app(TaxonomyAssignments::class)->sync($host, 'category', [$foreign], SystemActor::Background))->toThrow(OwnershipViolation::class);
        expect(fn () => app(TaxonomyAssignments::class)->sync($foreignHost, 'category', [$source], SystemActor::Background))->toThrow(OwnershipViolation::class);
        $merged = app(TaxonomyTerms::class)->merge($source, $destination, SystemActor::Background, $source->revision, $destination->revision);
        expect($child->fresh()->parent_id)->toBe($destination->id)->and(Termable::query()->firstOrFail()->term_id)->toBe($destination->id);
        app(TaxonomyTerms::class)->delete($merged, $merged->revision, SystemActor::Background, DeleteTermStrategy::Cascade);
        expect(TermModelRegistry::query()->count())->toBe(0);
        $this->artisan('nvl:taxonomy:prune', ['--force' => true])->assertSuccessful();
    });
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(8), OwnershipActor::firstOrFail(), function () use ($foreign): void {
        expect(TermModelRegistry::query()->findOrFail($foreign->id)->displayName('en'))->toBe('visible')->and(Termable::query()->count())->toBe(1);
    });
});
it('rejects raw cross owner tree references attachment pivots and direct relationship writes', function (): void {
    $foreign = null;
    $foreignHost = null;
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(8), OwnershipActor::firstOrFail(), function () use (&$foreign, &$foreignHost): void {
        $foreign = Scenario::term();
        $foreignHost = Scenario::host();
    });
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function () use ($foreign): void {
        $local = Scenario::term();
        $host = Scenario::host();
        app(TaxonomyAssignments::class)->sync($host, 'category', [$local], SystemActor::Background);
        expect(fn () => DB::transaction(fn () => DB::table($local->getTable())->where('id', $local->id)->update(['parent_id' => $foreign->id])))->toThrow(QueryException::class);
        $row = Termable::query()->firstOrFail()->getRawOriginal();
        $row['id'] = (string) Str::uuid();
        $row['term_id'] = $foreign->id;
        expect(fn () => DB::transaction(fn () => DB::table((new Termable)->getTable())->insert($row)))->toThrow(QueryException::class);
        expect(fn () => $local->entries($host::class)->attach($host->id))->toThrow(OwnershipViolation::class);
        $locale = $local->translations->first();
        $locale->term_id = $foreign->id;
        expect(fn () => $locale->saveQuietly())->toThrow(OwnershipViolation::class);
    });
});
it('keeps authorized reads bounded for one twenty five and one hundred hosts and scopes before pagination', function (): void {
    app()->bind(TaxonomyBatchAuthorization::class, fn () => new BatchTaxonomyPolicy(false));
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        $term = Scenario::term();
        $host = Scenario::host();
        app(TaxonomyAssignments::class)->sync($host, 'category', [$term], SystemActor::Background);
        $assignment = Termable::query()->sole();
        $hostIds = [$host->id];
        $hostRows = [];
        $assignmentRows = [];
        for ($index = 1; $index < 100; $index++) {
            $hostId = $host->newUniqueId();
            $hostIds[] = $hostId;
            $hostRows[] = [...$host->getRawOriginal(), 'id' => $hostId];
            $assignmentRows[] = [...$assignment->getRawOriginal(), 'id' => $assignment->newUniqueId(), 'termable_id' => $hostId];
        }
        $connection = $host->getConnection();
        $connection->transaction(static function () use ($connection, $host, $assignment, $hostRows, $assignmentRows): void {
            $connection->table($host->getTable())->insert($hostRows);
            $connection->table($assignment->getTable())->insert($assignmentRows);
        });
        $persisted = $host->newQuery()->whereKey($hostIds)->get()->keyBy('id');
        $hosts = array_map($persisted->get(...), $hostIds);

        $measure = function (array $hosts): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $result = app(TaxonomyReader::class)->forOwners($hosts, ['category'], SystemActor::Background, 'en');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
            expect($result['order'])->toHaveCount(count($hosts));

            return $count;
        };
        $one = $measure([$hosts[0]]);
        expect($measure(array_slice($hosts, 0, 25)))->toBeLessThanOrEqual($one + 2)
            ->and($measure($hosts))->toBeLessThanOrEqual($one + 2);
        expect(TermModelRegistry::query()->orderBy('id')->paginate(1)->total())->toBe(1);
    });
});
it('captures scalar owner authority before committed tree events leave their scope', function (): void {
    $events = [];
    Event::listen(TermChanged::class, function (TermChanged $event) use (&$events): void {
        $events[] = $event;
    });
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), fn () => Scenario::term());
    expect($events)->toHaveCount(1)->and($events[0])->toBeInstanceOf(OwnershipQueuedJob::class)
        ->and($events[0]->ownershipJobEnvelope()->data['owner']['key'])->toBe('7');
});
it('refuses adopted storage when enforcement is disabled', function (): void {
    config(['nvl-ownership.enabled' => false]);
    expect(fn () => OwnershipTerm::query()->count())->toThrow(OwnershipException::class);
});

it('keeps bulk attachment writes inside the actual source lease through commit callbacks', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        $term = Scenario::term();
        $host = Scenario::host();
        $source = $term->getConnection();
        $armed = true;
        $source->beforeExecuting(function (string $sql) use ($source, &$armed): void {
            if ($armed && str_starts_with(strtolower($sql), 'insert into') && str_contains($sql, 'termables')) {
                $armed = false;
                $source->commit();
            }
        });
        expect(fn () => app(TaxonomyAssignments::class)->sync($host, 'category', [$term], SystemActor::Background))->toThrow(OwnershipViolation::class);
    });
    expect(DB::table((new Termable)->getTable())->count())->toBe(0);
});

it('rejects raw host identities belonging to another native owner', function (): void {
    $foreignHost = null;
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(8), OwnershipActor::firstOrFail(), function () use (&$foreignHost): void {
        $foreignHost = Scenario::host();
    });
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function () use ($foreignHost): void {
        $term = Scenario::term();
        $host = Scenario::host();
        app(TaxonomyAssignments::class)->sync($host, 'category', [$term], SystemActor::Background);
        $row = Termable::query()->firstOrFail()->getRawOriginal();
        $row['id'] = (string) Str::uuid();
        $row['termable_id'] = $foreignHost->id;
        expect(fn () => DB::transaction(fn () => DB::table((new Termable)->getTable())->insert($row)))->toThrow(QueryException::class);
    });
});
