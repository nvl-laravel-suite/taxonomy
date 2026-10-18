<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Taxonomy\Concerns\GuardsTenantOwnership;
use Nvl\Taxonomy\Database\Factories\TermableFactory;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Ownership\TaxonomyOwnership;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

/**
 * UUID-backed pivot model for taxonomy attachments.
 */
final class TermablePivot extends MorphPivot
{
    use GuardsTenantOwnership;

    /** @use HasFactory<TermableFactory> */
    use HasFactory;

    use HasUuids;

    /** Deny direct pivot persistence outside package workflows.
     * @param  array<string,mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if (TaxonomyOwnership::declared()) {
            throw new OwnershipViolation('Use Taxonomy attachment workflows.');
        }

        return parent::save($options);
    }

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
        return TaxonomyConfiguration::table(TaxonomyTables::get(TaxonomyTables::Termables), TaxonomyTables::get(TaxonomyTables::Termables));
    }

    /**
     * Return the configured taxonomy storage connection.
     */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('taxonomy') ?? parent::getConnectionName());
    }

    /** Return the shared attachment fixture factory.
     *
     * @internal
     */
    protected static function newFactory(): TermableFactory
    {
        return TermableFactory::new();
    }
}
