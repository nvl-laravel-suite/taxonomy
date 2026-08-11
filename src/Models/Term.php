<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Relations\StringMorphToMany;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable;

/**
 * Structural taxonomy term whose display copy exists only in locale rows.
 *
 * @property string $id
 * @property string $taxonomy
 * @property string|null $parent_id
 * @property string $parent_key
 * @property string $slug
 * @property int $position
 * @property array<string, mixed>|null $meta
 * @property int $revision
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Term|null $parent
 * @property-read Collection<int, Term> $children
 * @property-read Collection<int, TermTranslation> $translations
 * @property-read Collection<int, Termable> $attachments
 */
class Term extends Model implements TranslatableModel
{
    use HasUuids;
    use Translatable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'parent_key' => '__root__',
        'position' => 0,
        'revision' => 1,
    ];

    /** @var list<string> */
    protected $fillable = [
        'taxonomy',
        'parent_id',
        'slug',
        'position',
        'meta',
    ];

    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(
            translationModel: TermTranslation::class,
            foreignKey: 'term_id',
            fields: ['name', 'description'],
            mutationPolicy: TranslationMutationPolicy::DomainActionOnly,
        );
    }

    /**
     * Return the configured structural term table.
     */
    public function getTable(): string
    {
        return TaxonomyConfiguration::table(TaxonomyTables::Terms, TaxonomyTables::Terms);
    }

    /**
     * Return the configured taxonomy storage connection.
     */
    public function getConnectionName(): ?string
    {
        return TaxonomyConfiguration::connection() ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'meta' => 'array',
            'revision' => 'integer',
        ];
    }

    /**
     * Return this term's direct registered-model parent.
     *
     * @return BelongsTo<static, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    /**
     * Return this term's ordered direct registered-model children.
     *
     * @return HasMany<static, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id')->ordered($this->taxonomy);
    }

    /**
     * Return raw polymorphic attachment rows for this term.
     *
     * @return HasMany<Termable, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Termable::class, 'term_id');
    }

    /**
     * Apply deterministic configured vocabulary ordering.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOrdered(Builder $query, ?string $taxonomy = null): void
    {
        $taxonomyAttribute = $taxonomy ?? $this->getAttribute('taxonomy');
        $taxonomy = is_string($taxonomyAttribute) && $taxonomyAttribute !== ''
            ? $taxonomyAttribute
            : static::taxonomyName();
        $sort = config("taxonomy.taxonomies.{$taxonomy}.sort", 'position');
        $column = is_string($sort) && in_array($sort, ['position', 'slug', 'created_at'], true)
            ? $sort
            : 'position';

        $query->orderBy($column)->orderBy('id');
    }

    /**
     * Return the default vocabulary name for the generic term model.
     */
    public static function taxonomyName(): string
    {
        return 'tag';
    }

    /**
     * @param  class-string<Model>  $type
     * @return MorphToMany<Model, $this>
     */
    public function entries(string $type): MorphToMany
    {
        $related = new $type;

        return (new StringMorphToMany(
            $related->newQuery(),
            $this,
            'termable',
            TaxonomyConfiguration::table(TaxonomyTables::Termables, TaxonomyTables::Termables),
            'term_id',
            'termable_id',
            $this->getKeyName(),
            $related->getKeyName(),
            'entries',
            true,
        ))
            ->using(TermablePivot::class)
            ->withPivot('position', 'taxonomy')
            ->withTimestamps();
    }

    /**
     * Return ancestors from direct parent to root.
     *
     * @return Collection<int, static>
     */
    public function ancestors(): Collection
    {
        $ancestors = new Collection;
        $parentId = $this->parent_id;
        $visited = [$this->id => true];

        while ($parentId !== null && ! isset($visited[$parentId])) {
            $parent = static::query()
                ->where('taxonomy', $this->taxonomy)
                ->find($parentId);

            if (! $parent instanceof Term) {
                break;
            }

            $visited[$parent->id] = true;
            $ancestors->push($parent);
            $parentId = $parent->parent_id;
        }

        return $ancestors;
    }

    /**
     * Return descendants in deterministic preorder using one term query.
     *
     * @return Collection<int, static>
     */
    public function descendants(): Collection
    {
        $grouped = static::query()
            ->where('taxonomy', $this->taxonomy)
            ->ordered($this->taxonomy)
            ->get()
            ->groupBy(static fn (Term $term): string => $term->parent_id ?? '__root__');
        $descendants = new Collection;
        $visited = [$this->id => true];
        $append = function (string $parentId) use (&$append, $grouped, $descendants, &$visited): void {
            /** @var Collection<int, Term> $children */
            $children = $grouped->get($parentId, new Collection);

            foreach ($children as $child) {
                if (isset($visited[$child->id])) {
                    continue;
                }

                $visited[$child->id] = true;
                $descendants->push($child);
                $append($child->id);
            }
        };
        $append($this->id);

        return $descendants;
    }

    /**
     * Return the resolved display name for one locale.
     */
    public function displayName(?string $locale = null): string
    {
        $value = $this->translated('name', $locale);

        return is_string($value) ? $value : '';
    }

    /**
     * Return the resolved display description for one locale.
     */
    public function displayDescription(?string $locale = null): ?string
    {
        $value = $this->translated('description', $locale);

        return is_string($value) ? $value : null;
    }

    protected static function booted(): void
    {
        self::saving(function (Term $term): void {
            $term->parent_key = $term->parent_id ?? '__root__';

            if ($term->exists && ! $term->isDirty('revision')) {
                $revision = $term->getOriginal('revision');
                $term->revision = (is_int($revision) ? $revision : 0) + 1;
            }
        });
    }
}
