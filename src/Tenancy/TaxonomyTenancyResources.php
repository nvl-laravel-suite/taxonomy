<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tenancy;

use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Models\TermTranslation;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers Taxonomy's immutable tenant-owned tree graph. */
final readonly class TaxonomyTenancyResources
{
    /** Register terms, owner attachments, and locale rows. */
    public function register(TenantResourceRegistry $resources): void
    {
        $resources->registerParentResolver('taxonomy.attachments', TaxonomyOwnerRegistry::class);
        $resources->register(new TenantResourceDefinition('taxonomy.terms', 'taxonomy', Term::class));
        $resources->register(new TenantResourceDefinition(
            'taxonomy.attachments',
            'taxonomy',
            Termable::class,
            TenantResourceKind::Inherited,
            null,
            'termable',
        ));
        $resources->register(new TenantResourceDefinition(
            'taxonomy.translations',
            'taxonomy',
            TermTranslation::class,
            TenantResourceKind::Inherited,
            'taxonomy.terms',
            'term',
        ));
    }
}
