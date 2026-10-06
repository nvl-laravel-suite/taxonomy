<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Nvl\Taxonomy\Models\Term;

/** Registered vocabulary fixture whose hidden structural terms stay private. */
final class BatchScopedTerm extends Term
{
    /** Keep the host vocabulary visibility scope on every term read. */
    protected static function booted(): void
    {
        parent::booted();
        self::addGlobalScope('batch_visibility', static function (Builder $query): void {
            $query->where($query->getModel()->qualifyColumn('slug'), '!=', 'hidden');
        });
    }
}
