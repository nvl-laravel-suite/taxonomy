<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Canonical owner fixture retaining a visibility scope and soft deletion. */
final class BatchScopedPost extends Post
{
    use SoftDeletes;

    /** Keep canonical admission independent of forged model attributes. */
    protected static function booted(): void
    {
        self::addGlobalScope('batch_visibility', static function (Builder $query): void {
            $query->where($query->getModel()->qualifyColumn('title'), 'visible');
        });
    }
}
