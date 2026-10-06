<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Taxonomy\Concerns\HasTaxonomies;
use Nvl\Taxonomy\Models\Term;

/**
 * A registered owner stored on the same physical table as vocabulary terms.
 *
 * @property string $id
 */
final class BatchTermTableOwner extends Model
{
    use HasTaxonomies;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Resolve the actual configurable structural term table. */
    public function getTable(): string
    {
        $table = config('taxonomy-test.overlap_owner_table');

        return is_string($table) ? $table : (new Term)->getTable();
    }

    /** Retain canonical package storage for the owner fixture. */
    public function getConnectionName(): ?string
    {
        return (new Term)->getConnectionName();
    }
}
