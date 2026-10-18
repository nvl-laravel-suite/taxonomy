<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Taxonomy\Models\Term;

/** A host term root using its native Company foreign key. */
class OwnershipTerm extends Term
{
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(OwnershipCompany::class, 'company_id');
    }

    protected static function booted(): void
    {
        parent::booted();
        static::creating(static function (self $term): void {
            $term->meta = ['configured' => true];
        });
    }
}
