<?php

declare(strict_types=1);
use Illuminate\Database\QueryException;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Taxonomy\Services\TermModelRegistry;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipActor;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipCompany;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipTaxonomyScenario;
use Nvl\Taxonomy\Tests\Fixtures\PivotOwnershipTerm;
use Nvl\Taxonomy\Tests\OwnershipPivotTestCase;

uses(OwnershipPivotTestCase::class);
it('resolves exclusive native pivots and prevents raw primary detach or reassignment', function (): void {
    foreach ([7, 8] as $id) {
        app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail($id), OwnershipActor::firstOrFail(), function (): void {
            $definition = OwnershipTaxonomyScenario::term('pivot');
            expect($definition::class)->toBe(PivotOwnershipTerm::class)->and(TermModelRegistry::query()->count())->toBe(1);
            $connection = $definition->getConnection();
            expect(fn () => $connection->transaction(fn () => $connection->table('taxonomy_term_owners')->where('term_id', $definition->id)->delete()))->toThrow(QueryException::class);
        });
    }
});
