<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Taxonomy\Concerns\GuardsOwnerScope;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Enums\TaxonomyMembershipMode;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

/**
 * Stores the latest reviewed provider proposal per owner, taxonomy and source, including shadow decisions.
 *
 * @property string $id
 * @property string|null $owner_scope_key Canonical owner scope inherited from the owner.
 * @property string $owner_type
 * @property string $owner_id
 * @property string $taxonomy
 * @property string $source
 * @property list<string> $proposal Proposed term ids or slugs; empty when the provider proposes no terms.
 * @property TaxonomyMembershipMode $mode
 * @property CarbonImmutable $matched_at
 * @property-read Model $owner
 *
 * @api
 */
final class MembershipMatch extends Model implements Ownable
{
    use GuardsOwnerScope;
    use HasOwnership;
    use HasUuids;

    public $timestamps = false;

    /** Explicit host subclasses opt roots into ownership; dependent metadata is fixed by the registrar. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::unscoped();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proposal' => 'array',
            'mode' => TaxonomyMembershipMode::class,
            'matched_at' => 'immutable_datetime',
        ];
    }

    /**
     * Return the owner whose memberships the provider proposed.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Return the configured membership matches table.
     */
    public function getTable(): string
    {
        return TaxonomyConfiguration::table(TaxonomyTables::MembershipMatches);
    }

    /**
     * Return the configured taxonomy storage connection.
     */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('taxonomy') ?? parent::getConnectionName());
    }
}
