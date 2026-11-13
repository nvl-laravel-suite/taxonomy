<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\OptsIntoAdoptedSchemaReuse;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Taxonomy\Exceptions\InvalidParentException;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Services\TaxonomyAssignments;
use Nvl\Taxonomy\Services\TaxonomyReader;
use Nvl\Taxonomy\Services\TaxonomyTerms;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipActor;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipCompany;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipTaxonomyScenario as Scenario;
use Nvl\Taxonomy\Tests\OwnershipTestCase;

uses(OwnershipTestCase::class, OptsIntoAdoptedSchemaReuse::class);

it('rejects foreign owner moves and assignment sync without changing local records', function (): void {
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::firstOrFail();
    $foreign = $runner->run(OwnershipCompany::findOrFail(8), $actor, fn () => Scenario::term('foreign'));

    $runner->run(OwnershipCompany::findOrFail(7), $actor, function () use ($foreign): void {
        $local = Scenario::term('local');
        $host = Scenario::host();
        app(TaxonomyAssignments::class)->sync($host, 'category', [$local], SystemActor::Background);

        expect(fn () => app(TaxonomyTerms::class)->move($local, $foreign, SystemActor::Background, 0, $local->revision))
            ->toThrow(InvalidParentException::class);
        expect(fn () => app(TaxonomyAssignments::class)->sync($host, 'category', [$foreign], SystemActor::Background))
            ->toThrow(OwnershipViolation::class);
        expect($local->fresh()->parent_id)->toBeNull()
            ->and(Termable::query()->where('termable_id', $host->id)->pluck('term_id')->all())->toBe([$local->id]);
    });
});

it('requires a persisted authorized actor for native reads', function (): void {
    $actor = OwnershipActor::firstOrFail();
    $reader = app(TaxonomyReader::class);

    expect(fn () => $reader->forOwners([], [], new OwnershipActor))->toThrow(AuthorizationException::class);

    Gate::define('taxonomy.read', static fn (OwnershipActor $principal): bool => false);
    expect(fn () => $reader->forOwners([], [], $actor))->toThrow(AuthorizationException::class);

    Gate::define('taxonomy.read', static fn (OwnershipActor $principal): bool => $principal->getKey() === $actor->getKey());
    $result = $reader->forOwners([], [], $actor);
    expect($result['owners'])->toBeInstanceOf(stdClass::class)
        ->and($result['order'])->toBe([]);
});
