<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Taxonomy\Concerns\GuardsTenantOwnership;
use Nvl\Taxonomy\Database\Factories\TermableFactory;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Services\TermModelRegistry;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

/**
 * Represents one polymorphic attachment row in the configured termables table.
 *
 * @property string $id
 * @property string|null $tenant_id Canonical tenant UUID inherited from the owner and term.
 * @property string $term_id
 * @property string $termable_type
 * @property int|string $termable_id
 * @property string $taxonomy
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Term $term
 * @property-read Model $termable
 *
 * @api
 */
final class Termable extends Model implements Ownable
{
    use GuardsTenantOwnership;
    use HasOwnership;

    /** Explicit host subclasses opt roots into ownership; dependent metadata is fixed by the registrar. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::unscoped();
    }

    /** @use HasFactory<TermableFactory> */
    use HasFactory;

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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * Return the canonical attached taxonomy term.
     *
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(TermModelRegistry::modelClass(), 'term_id');
    }

    /**
     * Return the polymorphic attachment owner.
     *
     * @return MorphTo<Model, $this>
     */
    public function termable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Return the configured taxonomy pivot table.
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

    /**
     * Return the package's runtime fixture factory.
     *
     * @internal
     */
    protected static function newFactory(): TermableFactory
    {
        return TermableFactory::new();
    }
}
