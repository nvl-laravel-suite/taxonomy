<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

/**
 * Stores localized term names and descriptions.
 *
 * @property string $id
 * @property string $term_id
 * @property string $locale
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Term $term
 */
final class TermTranslation extends Model
{
    use HasUuids;

    protected $fillable = [
        'term_id',
        'locale',
        'name',
        'description',
    ];

    /**
     * Return the configured taxonomy translation table.
     */
    public function getTable(): string
    {
        return TaxonomyConfiguration::table(TaxonomyTables::I18n, TaxonomyTables::I18n);
    }

    /**
     * Return the configured taxonomy storage connection.
     */
    public function getConnectionName(): ?string
    {
        return TaxonomyConfiguration::connection() ?? parent::getConnectionName();
    }

    /**
     * Return the canonical taxonomy term for this locale row.
     *
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'term_id');
    }
}
