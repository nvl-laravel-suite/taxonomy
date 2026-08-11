<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

/**
 * Represents one polymorphic attachment row in the configured termables table.
 *
 * @property string $id
 * @property string $term_id
 * @property string $termable_type
 * @property int|string $termable_id
 * @property string $taxonomy
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Term $term
 * @property-read Model $termable
 */
final class Termable extends Model
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
        return $this->belongsTo(Term::class, 'term_id');
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
        return TaxonomyConfiguration::table(TaxonomyTables::Termables, TaxonomyTables::Termables);
    }

    /**
     * Return the configured taxonomy storage connection.
     */
    public function getConnectionName(): ?string
    {
        return TaxonomyConfiguration::connection() ?? parent::getConnectionName();
    }
}
