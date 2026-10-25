<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Taxonomy\Services\TermModelRegistry;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipCompany;
use Nvl\Taxonomy\Tests\HistoricalOwnershipTestCase;

uses(HistoricalOwnershipTestCase::class);

it('refuses adoption of a tree mapped across two native owners', function (): void {
    $root = TermModelRegistry::newInstance();
    $parent = (string) Str::uuid();
    $child = (string) Str::uuid();
    DB::table($root->getTable())->insert(['id' => $parent, 'taxonomy' => 'category', 'slug' => 'parent']);
    DB::table($root->getTable())->insert(['id' => $child, 'taxonomy' => 'category', 'slug' => 'child', 'parent_id' => $parent, 'parent_key' => $parent]);

    $identities = app(OwnerIdentityResolver::class);
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $operation = new OwnershipOperation('verify-taxonomy', 'operator', '1');
    $plan = $coordinator->prepare(['taxonomy', 'fixture'], [
        new OwnershipMapping('taxonomy.terms', $parent, $identities->reference(OwnershipCompany::findOrFail(7))),
        new OwnershipMapping('taxonomy.terms', $child, $identities->reference(OwnershipCompany::findOrFail(8))),
    ], $operation);
    while (! $coordinator->backfill($plan, 1, $operation)) {
        $plan = $coordinator->resume($plan->id);
    }

    expect(fn () => $coordinator->activate($plan, $operation))->toThrow(OwnershipConfigurationInvalid::class);
});
