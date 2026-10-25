<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Taxonomy\Actions\SyncTermAttachmentsAction;
use Nvl\Taxonomy\Contracts\ListOwnerTaxonomyTermsContract;
use Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Models\TermTranslation;
use Nvl\Taxonomy\Services\TaxonomyBatchOwnerAdmission;
use Nvl\Taxonomy\Tests\Fixtures\BatchTaxonomyPolicy;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Taxonomy\Tests\Fixtures\TaxonomyTenancyScenario;

beforeEach(function (): void {
    $driver = getenv('NVL_C1_TEST_DRIVER');
    if (is_string($driver) && $driver !== '') {
        expect((new Term)->getConnection()->getDriverName())->toBe($driver);
    }
    $this->taxonomyTenantMorphMap = Relation::morphMap();
    $this->taxonomyTenantRequiresMorphMap = Relation::requiresMorphMap();
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
});

afterEach(function (): void {
    (new Term)->getConnection()->disableQueryLog();
    Relation::morphMap($this->taxonomyTenantMorphMap, false);
    Relation::requireMorphMap($this->taxonomyTenantRequiresMorphMap);
});

/**
 * Find package SQL independently of quote grammar and configured physical tables.
 *
 * @param  list<array{query: string, bindings: array<mixed>, time: float}>  $queries
 * @return list<array{query: string, bindings: array<mixed>, time: float}>
 */
function taxonomyTenantBatchOwnedQueries(array $queries): array
{
    $tables = [(new Term)->getTable(), (new Termable)->getTable(), (new TermTranslation)->getTable()];

    return array_values(array_filter($queries, static function (array $query) use ($tables): bool {
        $sql = str_replace(['"', '`', '[', ']'], '', strtolower($query['query']));
        foreach ($tables as $table) {
            if (preg_match('/\\b(?:from|join)\\s+'.preg_quote(strtolower($table), '/').'\\b/', $sql) === 1) {
                return true;
            }
        }

        return false;
    }));
}

it('keeps the real enabled tenant query budget fixed for one twenty five and one hundred owners', function (int $vocabularyCount): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    $scenario = TaxonomyTenancyScenario::install();
    $term = $scenario->term(TaxonomyTenancyScenario::A, 'visible');
    $category = $vocabularyCount === 2 ? $scenario->term(TaxonomyTenancyScenario::A, 'category', 'category') : null;
    $owners = [];
    for ($index = 0; $index < 100; $index++) {
        $owner = $scenario->owner(TaxonomyTenancyScenario::A);
        $owners[] = $owner;
        $scenario->run(TaxonomyTenancyScenario::A, static fn () => app(SyncTermAttachmentsAction::class)->execute($owner, 'tag', [$term]));
        if ($category !== null) {
            $scenario->run(TaxonomyTenancyScenario::A, static fn () => app(SyncTermAttachmentsAction::class)->execute($owner, 'category', [$category]));
        }
    }
    $counts = $scenario->run(TaxonomyTenancyScenario::A, function () use ($reader, $owners, $vocabularyCount): array {
        $connection = (new Term)->getConnection();
        $reader->execute([$owners[0]], ['tag', 'category'], 'en');
        $counts = [];
        foreach ([1, 25, 100] as $count) {
            $connection->enableQueryLog();
            $connection->flushQueryLog();
            $result = $reader->execute(array_slice($owners, 0, $count), ['tag', 'category'], 'en');
            $counts[] = count($connection->getQueryLog());
            $connection->disableQueryLog();
            $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

            expect($json->order)->toHaveCount($count);
            foreach (array_slice($owners, 0, $count) as $owner) {
                $vocabularies = $json->owners->{$owner->getMorphClass()}->{(string) $owner->getKey()}->vocabularies;
                expect($vocabularies->tag)->toHaveCount(1)
                    ->and($vocabularies->tag[0]->name)->toBe('Visible');
                if ($vocabularyCount === 2) {
                    expect($vocabularies->category)->toHaveCount(1)
                        ->and($vocabularies->category[0]->name)->toBe('Category');
                } else {
                    expect($vocabularies->category)->toBe([]);
                }
            }
        }

        return $counts;
    });

    expect($counts)->toBe($vocabularyCount === 1 ? [4, 4, 4] : [6, 6, 6]);
})->with([1, 2]);

it('rejects canonically foreign tenant owners before package storage despite forged attributes', function (): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    $scenario = TaxonomyTenancyScenario::install();
    $local = $scenario->owner(TaxonomyTenancyScenario::A);
    $foreign = $scenario->owner(TaxonomyTenancyScenario::B);
    $foreign->setRawAttributes([...$foreign->getAttributes(), 'tenant_id' => TaxonomyTenancyScenario::A], true);

    $scenario->run(TaxonomyTenancyScenario::A, function () use ($reader, $local, $foreign): void {
        $connection = (new Term)->getConnection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        expect(fn () => $reader->execute([$local, $foreign], ['tag']))->toThrow(TenantBoundaryViolation::class);
        expect(taxonomyTenantBatchOwnedQueries($connection->getQueryLog()))->toBe([]);
    });
});

it('retains explicit owner boundaries when the caller removes host global scopes', function (): void {
    app(ListOwnerTaxonomyTermsContract::class);
    $scenario = TaxonomyTenancyScenario::install();
    $local = $scenario->owner(TaxonomyTenancyScenario::A);
    $foreign = $scenario->owner(TaxonomyTenancyScenario::B);
    $localTerm = $scenario->term(TaxonomyTenancyScenario::A, 'visible');
    $foreignTerm = $scenario->term(TaxonomyTenancyScenario::B, 'visible');
    $scenario->run(TaxonomyTenancyScenario::A, static fn () => app(SyncTermAttachmentsAction::class)->execute($local, 'tag', [$localTerm]));
    $scenario->run(TaxonomyTenancyScenario::B, static fn () => app(SyncTermAttachmentsAction::class)->execute($foreign, 'tag', [$foreignTerm]));

    $scenario->run(TaxonomyTenancyScenario::A, function () use ($local): void {
        $query = Post::withoutGlobalScopes()->select('id')->withAnyTerms('tag', ['visible']);
        expect($query->pluck('id')->all())->toBe([$local->getKey()])
            ->and(Post::withoutGlobalScopes()->withAllTerms('tag', ['visible'])->pluck('id')->all())->toBe([$local->getKey()])
            ->and(Post::withoutGlobalScopes()->withoutTerms('tag', ['missing'])->pluck('id')->all())->toBe([$local->getKey()]);
    });
});

it('uses native host mapped identity through the real inherited attachment graph', function (): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    Relation::morphMap(['Host.Taxonomy' => Post::class], false);
    $scenario = TaxonomyTenancyScenario::install();
    $owner = $scenario->owner(TaxonomyTenancyScenario::A);
    $term = $scenario->term(TaxonomyTenancyScenario::A, 'visible');
    $scenario->run(TaxonomyTenancyScenario::A, static fn () => app(SyncTermAttachmentsAction::class)->execute($owner, 'tag', [$term]));

    $scenario->run(TaxonomyTenancyScenario::A, function () use ($reader, $owner, $term): void {
        $result = $reader->execute([$owner], ['tag']);
        $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        expect(array_column($json->owners->{'Host.Taxonomy'}->{(string) $owner->getKey()}->vocabularies->tag, 'id'))->toBe([$term->id])
            ->and(Relation::morphMap())->toBe(['Host.Taxonomy' => Post::class]);
    });
});

it('keeps tenant boundaries outside private policy OR clauses for batch and host reads', function (): void {
    app(ListOwnerTaxonomyTermsContract::class);
    app()->instance(TaxonomyBatchAuthorization::class, new BatchTaxonomyPolicy);
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    $scenario = TaxonomyTenancyScenario::install();
    $local = $scenario->owner(TaxonomyTenancyScenario::A);
    $foreign = $scenario->owner(TaxonomyTenancyScenario::B);
    $visible = $scenario->term(TaxonomyTenancyScenario::A, 'visible');
    $hidden = $scenario->term(TaxonomyTenancyScenario::A, 'hidden');
    $foreignTerm = $scenario->term(TaxonomyTenancyScenario::B, 'visible');
    $scenario->run(TaxonomyTenancyScenario::A, static fn () => app(SyncTermAttachmentsAction::class)->execute($local, 'tag', [$visible, $hidden]));
    $scenario->run(TaxonomyTenancyScenario::B, static fn () => app(SyncTermAttachmentsAction::class)->execute($foreign, 'tag', [$foreignTerm]));

    $scenario->run(TaxonomyTenancyScenario::A, function () use ($reader, $local, $visible): void {
        $result = $reader->execute([$local], ['tag']);
        $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        expect(array_column($json->owners->{Post::class}->{(string) $local->getKey()}->vocabularies->tag, 'id'))->toBe([$visible->id])
            ->and(Post::withoutGlobalScopes()->withAnyTerms('tag', ['visible'], new BatchTaxonomyPolicy)->pluck('id')->all())->toBe([$local->getKey()]);
    });
});

it('groups direct helper caller OR clauses inside the real registered tenant boundary', function (): void {
    $scenario = TaxonomyTenancyScenario::install();
    $local = $scenario->owner(TaxonomyTenancyScenario::A);
    $scenario->owner(TaxonomyTenancyScenario::B);

    $scenario->run(TaxonomyTenancyScenario::A, function () use ($local): void {
        $query = Post::withoutGlobalScopes()->selectRaw('id, ? as marker', ['retained'])->where('title', 'absent')->orWhereRaw('1 = 1');
        $guarded = app(TaxonomyBatchOwnerAdmission::class)->scope($query);
        expect($guarded->get()->modelKeys())->toBe([$local->getKey()])
            ->and($guarded->firstOrFail()->getAttribute('marker'))->toBe('retained')
            ->and(Post::withoutGlobalScopes()->where('title', 'absent')->orWhereRaw('1 = 1')->withAllTerms('tag', [])->pluck('id')->all())->toBe([$local->getKey()]);
    });
});
