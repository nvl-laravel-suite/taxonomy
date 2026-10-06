<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Taxonomy\Concerns\HasTaxonomies;
use Nvl\Taxonomy\Models\Termable;

/**
 * A registered owner stored on the same physical table as taxonomy attachments.
 *
 * @property string $id
 */
final class BatchAttachmentTableOwner extends Model
{
    use HasTaxonomies;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Resolve the actual configurable inherited attachment table. */
    public function getTable(): string
    {
        return (new Termable)->getTable();
    }

    /** Retain canonical package storage for the owner fixture. */
    public function getConnectionName(): ?string
    {
        return (new Termable)->getConnectionName();
    }
}
