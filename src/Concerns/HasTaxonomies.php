<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Concerns;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Models\TermablePivot;
use Nvl\Taxonomy\Relations\StringMorphToMany;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;
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
            $definition = Container::getInstance()->make(TaxonomyRegistry::class)->get($taxonomy);

            static::resolveRelationUsing(
                Str::plural($taxonomy),
                function (Model $model) use ($definition, $taxonomy): MorphToMany {
                    $related = new ($definition->model);
                    $relation = new StringMorphToMany(
                        $related->newQuery(),
                        $model,
                        'termable',
                        TaxonomyConfiguration::table(TaxonomyTables::Termables, TaxonomyTables::Termables),
                        'termable_id',
                        'term_id',
                        $model->getKeyName(),
                        $related->getKeyName(),
                        Str::plural($taxonomy),
                    );
                    $relation->using(TermablePivot::class);
                    $relation->wherePivot('taxonomy', $taxonomy);
                    $tenant = self::currentTaxonomyTenant();
                    if ($tenant !== null) {
                        $relation->wherePivot('tenant_id', $tenant);
                    }

                    $relation->withPivot('position');
                    $relation->withTimestamps();
                    $relation->orderByPivot('position');

                    return $relation;
                },
            );
        }

        $deleteAttachments = static function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting() !== true) {
                return;
            }

            $canonical = Container::getInstance()->make(TaxonomyOwnerRegistry::class)->resolve($model, lock: true);
            $query = Termable::query()
                ->where('termable_type', $model->getMorphClass())
                ->where('termable_id', TaxonomyConfiguration::modelIdentifier($canonical));
            if (($tenant = self::currentTaxonomyTenant()) !== null) {
                $query->where('tenant_id', $tenant);
            }
            $query->delete();
        };

        static::deleting($deleteAttachments);
    }

    /**
     * Return raw taxonomy attachment records owned by this model.
     *
     * @return MorphMany<Termable, $this>
     */
    public function termables(): MorphMany
    {
        $relation = $this->morphMany(
            Termable::class,
            'termable',
            null,
            null,
            $this->getKeyName(),
        );
        if (($tenant = self::currentTaxonomyTenant()) !== null) {
            $relation->where('tenant_id', $tenant);
        }

        return $relation;
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
        self::assertRegisteredTaxonomyOwner($query->getModel());
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
        self::assertRegisteredTaxonomyOwner($query->getModel());
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
        self::assertRegisteredTaxonomyOwner($query->getModel());
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
        self::assertRegisteredTaxonomyOwner($query->getModel());
        $identifier = $category->getRawOriginal($category->getKeyName());
        if (! is_string($identifier)) {
            throw new TenantBoundaryViolation('A canonical category identifier is required.');
        }
        $category = Term::query()->findOrFail($identifier);
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
        $canonicalOwner = Container::getInstance()->make(TaxonomyOwnerRegistry::class)->resolve($this);
        $relation = Str::plural($taxonomy);

        if ($value instanceof Term) {
            $identifier = $value->getRawOriginal($value->getKeyName());
            if (! is_string($identifier)) {
                throw new TenantBoundaryViolation('A canonical taxonomy term identifier is required.');
            }
            $value = Term::query()->findOrFail($identifier);
        }

        if ($this->relationLoaded($relation)) {
            $collection = $this->getRelation($relation);

            if (! $collection instanceof Collection) {
                return false;
            }

            $tenant = self::currentTaxonomyTenant();
            foreach ($collection as $loadedTerm) {
                if (! $loadedTerm instanceof Term) {
                    throw new TenantBoundaryViolation('A loaded taxonomy relation contains an invalid term.');
                }
                Container::getInstance()->make(TenantBoundary::class)->assertRecord($loadedTerm, 'taxonomy.terms');
                if ($loadedTerm->getRawOriginal('taxonomy') !== $taxonomy
                    || ($tenant !== null && $loadedTerm->getRawOriginal('tenant_id') !== $tenant)) {
                    throw new TenantBoundaryViolation('A loaded taxonomy relation contains a foreign term.');
                }
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
            ->where('termable_type', $canonicalOwner->getMorphClass())
            ->where('termable_id', TaxonomyConfiguration::modelIdentifier($canonicalOwner))
            ->where('taxonomy', $taxonomy)
            ->when(
                self::currentTaxonomyTenant() !== null,
                static fn (Builder $builder) => $builder->where('tenant_id', self::currentTaxonomyTenant()),
            )
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

    /** Require the consumer model to have a canonical Foundation ownership declaration. */
    private static function assertRegisteredTaxonomyOwner(Model $model): void
    {
        Container::getInstance()->make(TaxonomyOwnerRegistry::class)->aliasFor($model);
        if (config('tenancy.enabled') === true) {
            Container::getInstance()->make(TenantResourceRegistry::class)->forModel($model);
        }
    }

    /** Resolve the active tenant used by pivot correlations, preserving disabled compatibility. */
    private static function currentTaxonomyTenant(): ?string
    {
        if (config('tenancy.enabled') !== true) {
            return null;
        }

        return Container::getInstance()->make(TenantContext::class)->requireTenant()->value;
    }
}
