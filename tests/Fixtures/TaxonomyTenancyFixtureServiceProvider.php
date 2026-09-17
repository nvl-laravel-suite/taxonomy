<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ServiceProvider;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers the fixture owner resource, adapter, and ordinary query scope. */
final class TaxonomyTenancyFixtureServiceProvider extends ServiceProvider
{
    /** Register fixture ownership before final configuration validation. */
    public function boot(
        TenantResourceRegistry $resources,
        TenantAdoptionRegistry $adoptions,
        TenantBoundary $boundary,
    ): void {
        $resources->register(new TenantResourceDefinition('test.taxonomy-owners', 'test.taxonomy-owners', Post::class));
        $adoptions->register('resource-fixture-owners', TenancyOwnerAdoptionAdapter::class);
        Post::addGlobalScope('tenant', static function (Builder $query) use ($boundary): void {
            $boundary->query($query, 'test.taxonomy-owners');
        });
    }
}
