<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Models\TermablePivot;
use Nvl\Taxonomy\Relations\StringMorphToMany;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use ReflectionClass;

/**
 * @mixin Model
 */
trait HasTaxonomies
{
    /**
     * Register configured relations and owner lifecycle cleanup.
     */
    public static function bootHasTaxonomies(): void
    {
        foreach (static::configuredTaxonomies() as $taxonomy) {
            $definition = app(TaxonomyRegistry::class)->get($taxonomy);

            static::resolveRelationUsing(
                Str::plural($taxonomy),
                function (Model $model) use ($definition, $taxonomy): MorphToMany {
                    $related = new ($definition->model);

                    return (new StringMorphToMany(
                        $related->newQuery(),
                        $model,
                        'termable',
                        TaxonomyConfiguration::table('termables', 'termables'),
                        'termable_id',
                        'term_id',
                        $model->getKeyName(),
                        $related->getKeyName(),
                        Str::plural($taxonomy),
                    ))
                        ->using(TermablePivot::class)
                        ->wherePivot('taxonomy', $taxonomy)
                        ->withPivot('position')
                        ->withTimestamps()
                        ->orderByPivot('position');
                },
            );
        }

        $deleteAttachments = static function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting() !== true) {
                return;
            }

            Termable::query()
                ->where('termable_type', $model->getMorphClass())
                ->where('termable_id', TaxonomyConfiguration::modelIdentifier($model))
                ->delete();
        };

        static::deleting($deleteAttachments);
        static::deleted($deleteAttachments);
    }

    /**
     * Return raw taxonomy attachment records owned by this model.
     *
     * @return MorphMany<Termable, $this>
     */
    public function termables(): MorphMany
    {
        return $this->morphMany(
            Termable::class,
            'termable',
            null,
            null,
            $this->getKeyName(),
        );
    }

    /**
     * Restrict owners to any supplied exact term ID or slug.
     *
     * @param  Builder<static>  $query
     * @param  list<string|int>  $values
     * @return Builder<static>
     */
    public function scopeWithAnyTerms(Builder $query, string $taxonomy, array $values): Builder
    {
        $values = array_values(array_unique($values, SORT_REGULAR));
        self::assertScopeTermLimit($values);

        if ($values === []) {
            return $query->whereRaw('1 = 0');
        }

        [$identifiers, $slugs] = self::partitionTermReferences($values);

        return $query->whereHas(Str::plural($taxonomy), function (Builder $q) use ($identifiers, $slugs): void {
            $q->where(static function (Builder $terms) use ($identifiers, $slugs): void {
                $model = $terms->getModel();

                if ($slugs !== []) {
                    $terms->whereIn($model->qualifyColumn('slug'), $slugs);
                }

                if ($identifiers !== []) {
                    $method = $slugs === [] ? 'whereIn' : 'orWhereIn';
                    $terms->{$method}($model->getQualifiedKeyName(), $identifiers);
                }
            });
        });
    }

    /**
     * Restrict owners to every supplied term reference.
     *
     * @param  Builder<static>  $query
     * @param  list<string|int>  $values
     * @return Builder<static>
     */
    public function scopeWithAllTerms(Builder $query, string $taxonomy, array $values): Builder
    {
        $values = array_values(array_unique($values, SORT_REGULAR));
        self::assertScopeTermLimit($values);

        if ($values === []) {
            return $query;
        }

        foreach ($values as $value) {
            $query->whereHas(Str::plural($taxonomy), function (Builder $q) use ($value): void {
                $reference = (string) $value;
                $model = $q->getModel();

                if (Str::isUuid($reference)) {
                    $q->where($model->getQualifiedKeyName(), $reference);

                    return;
                }

                $q->where($model->qualifyColumn('slug'), $reference);
            });
        }

        return $query;
    }

    /**
     * Exclude owners attached to any supplied term reference.
     *
     * @param  Builder<static>  $query
     * @param  list<string|int>  $values
     * @return Builder<static>
     */
    public function scopeWithoutTerms(Builder $query, string $taxonomy, array $values): Builder
    {
        $values = array_values(array_unique($values, SORT_REGULAR));
        self::assertScopeTermLimit($values);

        if ($values === []) {
            return $query;
        }

        [$identifiers, $slugs] = self::partitionTermReferences($values);

        return $query->whereDoesntHave(Str::plural($taxonomy), function (Builder $q) use ($identifiers, $slugs): void {
            $q->where(static function (Builder $terms) use ($identifiers, $slugs): void {
                $model = $terms->getModel();

                if ($slugs !== []) {
                    $terms->whereIn($model->qualifyColumn('slug'), $slugs);
                }

                if ($identifiers !== []) {
                    $method = $slugs === [] ? 'whereIn' : 'orWhereIn';
                    $terms->{$method}($model->getQualifiedKeyName(), $identifiers);
                }
            });
        });
    }

    /**
     * Restrict owners to a category or any descendant category.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInCategory(Builder $query, Term $category, bool $includeDescendants = true): Builder
    {
        $ids = [$category->id];

        if ($includeDescendants) {
            foreach ($category->descendants() as $descendant) {
                $ids[] = $descendant->id;
            }
        }

        return $query->whereHas(Str::plural($category->taxonomy), function (Builder $q) use ($ids): void {
            $q->whereIn($q->getModel()->getQualifiedKeyName(), $ids);
        });
    }

    /**
     * Determine whether this owner has one exact taxonomy term.
     */
    public function hasTerm(string $taxonomy, string|int|Term $value): bool
    {
        $relation = Str::plural($taxonomy);

        if ($this->relationLoaded($relation)) {
            $collection = $this->getRelation($relation);

            if (! $collection instanceof Collection) {
                return false;
            }

            if ($value instanceof Term) {
                return $value->taxonomy === $taxonomy
                    && $collection->contains('id', $value->id);
            }

            $identifier = (string) $value;

            return Str::isUuid($identifier)
                ? $collection->contains('id', $identifier)
                : $collection->contains('slug', $identifier);
        }

        if ($value instanceof Term) {
            if ($value->taxonomy !== $taxonomy) {
                return false;
            }

            $termIds = [$value->id];
        } else {
            $identifier = (string) $value;
            $terms = Term::query()->where('taxonomy', $taxonomy);

            if (Str::isUuid($identifier)) {
                $terms->whereKey($identifier);
            } else {
                $terms->where('slug', $identifier);
            }

            $termIds = $terms->pluck('id')->all();
        }

        return Termable::query()
            ->whereIn('term_id', $termIds)
            ->where('termable_type', $this->getMorphClass())
            ->where('termable_id', TaxonomyConfiguration::modelIdentifier($this))
            ->where('taxonomy', $taxonomy)
            ->exists();
    }

    /**
     * Return taxonomy keys declared by the consuming model.
     *
     * @return list<string>
     */
    protected static function configuredTaxonomies(): array
    {
        $taxonomies = (new ReflectionClass(static::class))
            ->getDefaultProperties()['taxonomies'] ?? [];

        if (! is_array($taxonomies)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $taxonomies,
            static fn (mixed $taxonomy): bool => is_string($taxonomy) && $taxonomy !== '',
        )));
    }

    /**
     * Reject unbounded taxonomy scope input before building SQL.
     *
     * @param  list<string|int>  $values
     */
    private static function assertScopeTermLimit(array $values): void
    {
        if (count($values) > TaxonomyConfiguration::positiveLimit('bulk_terms', 500)) {
            throw new InvalidArgumentException('Too many taxonomy terms were supplied.');
        }
    }

    /**
     * Keep UUID predicates type-safe on databases with native UUID columns.
     *
     * @param  list<string|int>  $values
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function partitionTermReferences(array $values): array
    {
        $identifiers = [];
        $slugs = [];

        foreach ($values as $value) {
            $reference = (string) $value;

            if (Str::isUuid($reference)) {
                $identifiers[] = $reference;
            } else {
                $slugs[] = $reference;
            }
        }

        return [$identifiers, $slugs];
    }
}
