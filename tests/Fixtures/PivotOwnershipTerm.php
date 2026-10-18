<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Taxonomy\Models\Term;

/** Consumer-provided exclusive primary ownership pivot. */
final class PivotOwnershipTerm extends Term
{
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::pivot(OwnershipCompany::class, 'taxonomy_term_owners', 'term_id', 'company_id');
    }
}
