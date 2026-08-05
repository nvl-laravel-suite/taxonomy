<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

/**
 * UUID-backed pivot model for taxonomy attachments.
 */
final class TermablePivot extends MorphPivot
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'term_id',
        'termable_type',
        'termable_id',
        'taxonomy',
        'position',
    ];

    /**
     * Return package pivot casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * Return the configured taxonomy attachment table.
     */
    public function getTable(): string
    {
        return TaxonomyConfiguration::table('termables', 'termables');
    }

    /**
     * Return the configured taxonomy storage connection.
     */
    public function getConnectionName(): ?string
    {
        return TaxonomyConfiguration::connection() ?? parent::getConnectionName();
    }
}
