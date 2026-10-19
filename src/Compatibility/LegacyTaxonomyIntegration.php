<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Compatibility;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Support\Ownership\Compatibility\LegacyOwnershipSupport;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Models\TermTranslation;
use Nvl\Taxonomy\Ownership\TaxonomyBoundary as OwnershipBoundary;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Taxonomy\Tenancy\TaxonomyAdoptionAdapter;
use Nvl\Taxonomy\Tenancy\TaxonomyTenancyResources;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;

/** Contains only retained legacy graph registration and tenant predicates during the migration window. @internal */
final readonly class LegacyTaxonomyIntegration
{
    /** Retain provider infrastructure without storing an execution context. */
    public function __construct(private Container $app, private LegacyOwnershipSupport $legacy) {}

    /** Register the historical graph even for unowned hosts that still use its model metadata. */
    public function registerResources(): void
    {
        $this->app->make(TaxonomyTenancyResources::class)->register($this->app->make(TenantResourceRegistry::class));
        if ($this->app->bound(TenantAdoptionRegistry::class)) {
            $this->app->make(TenantAdoptionRegistry::class)->register('taxonomy', TaxonomyAdoptionAdapter::class);
        }
    }

    /** Install legacy predicates only when the legacy authority is explicitly selected. */
    public function bootScopes(): void
    {
        if ($this->legacy->enabled()) {
            $this->registerScopes($this->app->make(OwnershipBoundary::class), $this->app->make(TaxonomyRegistry::class));
        }
    }

    /** Register tenant predicates on every configured term model and inherited row. */
    public function registerScopes(
        OwnershipBoundary $boundary,
        TaxonomyRegistry $taxonomies,
    ): void {
        $termModels = [Term::class];

        foreach ($taxonomies->all() as $definition) {
            $termModels[] = $definition->model;
        }

        foreach (array_unique($termModels) as $termModel) {
            $termModel::addGlobalScope('tenant', static function (Builder $query) use ($boundary): void {
                $boundary->query($query, 'taxonomy.terms');
            });
        }

        Termable::addGlobalScope('tenant', static function (Builder $query) use ($boundary): void {
            $boundary->query($query, 'taxonomy.attachments');
        });
        TermTranslation::addGlobalScope('tenant', static function (Builder $query) use ($boundary): void {
            $boundary->query($query, 'taxonomy.translations');
        });
    }
}
