<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Canonical owner fixture with a host-defined soft-delete column.
 *
 * @property Carbon|null $archived_at
 */
final class BatchArchivedPost extends Post
{
    use SoftDeletes;

    public const string DELETED_AT = 'archived_at';
}
