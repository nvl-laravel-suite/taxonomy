<?php

declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Taxonomy\Models\TermTranslation;
use Nvl\Taxonomy\Services\TermModelRegistry;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipActor;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipCompany;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipTaxonomyScenario as Scenario;
use Nvl\Taxonomy\Tests\HistoricalOwnershipTestCase;

uses(HistoricalOwnershipTestCase::class);
it('converts released tenant constraints with exact mappings and admits new native locales', function (): void {
    (require __DIR__.'/../../database/tenancy/2026_09_16_120001_nvl_taxonomy_expand_taxonomy_tenant_ownership.php')->up();
    $legacy = '01900000-0000-7000-8000-000000000003';
    $id = (string) Str::uuid();
    $locale = (string) Str::uuid();
    $root = TermModelRegistry::newInstance();
    $source = $root->getConnection();
    $source->table($root->getTable())->insert(['id' => $id, 'taxonomy' => 'category', 'slug' => 'legacy', 'tenant_id' => $legacy]);
    $source->table((new TermTranslation)->getTable())->insert(['id' => $locale, 'term_id' => $id, 'locale' => 'en', 'name' => 'Legacy', 'tenant_id' => $legacy]);
    (require __DIR__.'/../../database/tenancy/2026_09_16_120002_nvl_taxonomy_constrain_taxonomy_tenant_ownership.php')->up();
    $owner = app(OwnerIdentityResolver::class)->reference(OwnershipCompany::findOrFail(7));
    $operation = new OwnershipOperation('convert-taxonomy', 'operator', '1');
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['taxonomy', 'fixture'], [new OwnershipMapping('taxonomy.terms', $id, $owner, legacyTenantId: $legacy), new OwnershipMapping('taxonomy.translations', $locale, $owner, legacyTenantId: $legacy)], $operation);
    while (! $coordinator->backfill($plan, 1, $operation)) {
        $plan = $coordinator->resume($plan->id);
    }
    $coordinator->activate($plan, $operation);
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function () use ($id): void {
        expect(TermModelRegistry::query()->findOrFail($id)->displayName('en'))->toBe('Legacy');
        $child = Scenario::term('new-child', $id);
        expect($child->parent_id)->toBe($id)->and($child->displayName('en'))->toBe('new-child');
    });
});
it('refuses activation for a tree mapped across two different native owners', function (): void {
    $root = TermModelRegistry::newInstance();
    $a = (string) Str::uuid();
    $b = (string) Str::uuid();
    DB::table($root->getTable())->insert(['id' => $a, 'taxonomy' => 'category', 'slug' => 'a']);
    DB::table($root->getTable())->insert(['id' => $b, 'taxonomy' => 'category', 'slug' => 'b', 'parent_id' => $a, 'parent_key' => $a]);
    $identity = app(OwnerIdentityResolver::class);
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $operation = new OwnershipOperation('verify-taxonomy', 'operator', '1');
    $plan = $coordinator->prepare(['taxonomy', 'fixture'], [new OwnershipMapping('taxonomy.terms', $a, $identity->reference(OwnershipCompany::findOrFail(7))), new OwnershipMapping('taxonomy.terms', $b, $identity->reference(OwnershipCompany::findOrFail(8)))], $operation);
    while (! $coordinator->backfill($plan, 1, $operation)) {
        $plan = $coordinator->resume($plan->id);
    }
    expect(fn () => $coordinator->activate($plan, $operation))->toThrow(OwnershipConfigurationInvalid::class);
});
