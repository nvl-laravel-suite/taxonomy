<?php

declare(strict_types=1);
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Taxonomy\Actions\CreateTermAction;
use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TaxonomyDoctor;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Taxonomy\Services\TermModelRegistry;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipActor;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipCompany;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipTerm;
use Nvl\Taxonomy\Tests\OwnershipTestCase;

uses(OwnershipTestCase::class);
it('uses the configured root for actions factories relations and route binding', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        $term = app(CreateTermAction::class)->execute(MutateTermPayload::from(['taxonomy' => 'category', 'slug' => 'root', 'translations' => ['en' => ['name' => 'Root']]]));
        expect($term::class)->toBe(OwnershipTerm::class)->and($term->meta)->toBe(['configured' => true])
            ->and(TermModelRegistry::query()->count())->toBe(1)
            ->and(Term::factory()->make()::class)->toBe(OwnershipTerm::class)
            ->and((new Term)->resolveRouteBinding($term->id)::class)->toBe(OwnershipTerm::class)
            ->and($term->translations->first()->term::class)->toBe(OwnershipTerm::class);
        expect(fn () => Term::query()->count())->toThrow(OwnershipViolation::class);
        $owners = app(TaxonomyOwnerRegistry::class);
        $owners->register('term_subjects', Term::class);
        $owners->register('term_subjects', Term::class);
        expect($owners->model('term_subjects'))->toBe(OwnershipTerm::class);
        $failures = array_values(array_filter(app(TaxonomyDoctor::class)->inspect(), static fn ($check) => ! $check->passed));
        expect(array_map(static fn ($check) => $check->key, $failures))->toBe([]);
    });
});
