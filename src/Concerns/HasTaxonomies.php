<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Concerns;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Models\TermablePivot;
use Nvl\Taxonomy\Ownership\TaxonomyBoundary as OwnershipBoundary;
use Nvl\Taxonomy\Ownership\TaxonomyOwnership;
use Nvl\Taxonomy\Relations\StringMorphToMany;
use Nvl\Taxonomy\Services\TaxonomyHostQueryAdapter;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Taxonomy\Services\TermModelRegistry;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use ReflectionClass;

/**
 * @mixin Model
 *
 * @api
 *
 * @nvl-consumer-relation termables
 */
trait HasTaxonomies
{
    /**
     * Register configured relations and owner lifecycle cleanup.
     *
     * @internal
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
                        TaxonomyConfiguration::table(TaxonomyTables::Termables),
                        'termable_id',
                        'term_id',
                        $model->getKeyName(),
                        $related->getKeyName(),
                        Str::plural($taxonomy),
                    );
                    $relation->using(TermablePivot::class);
                    $relation->wherePivot('taxonomy', $taxonomy);

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

            $canonical = Container::getInstance()->make(TaxonomyOwnerRegistry::class)->resolveForDeletion($model);
            $query = Termable::query()
                ->where('termable_type', $model->getMorphClass())
                ->where('termable_id', TaxonomyConfiguration::modelIdentifier($canonical));
            $query->delete();
        };

        static::deleting($deleteAttachments);
    }

    /**
     * Return raw taxonomy attachment records owned by this model.
     *
     * @return MorphMany<Termable, $this>
     *
     * @internal
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

        return $relation;
    }

    /**
     * Restrict owners to any supplied exact term ID or slug.
     *
     * @param  Builder<static>  $query
     * @param  list<string|int>  $values
     * @return Builder<static>
     */
    public function scopeWithAnyTerms(Builder $query, string $taxonomy, array $values, Authenticatable|SystemActor $actor, ?TaxonomyBatchAuthorization $policy = null): Builder
    {
        return Container::getInstance()->make(TaxonomyHostQueryAdapter::class)->filter($query, $taxonomy, $values, 'any', $actor, $policy);
    }

    /**
     * Restrict owners to every supplied term reference.
     *
     * @param  Builder<static>  $query
     * @param  list<string|int>  $values
     * @return Builder<static>
     */
    public function scopeWithAllTerms(Builder $query, string $taxonomy, array $values, Authenticatable|SystemActor $actor, ?TaxonomyBatchAuthorization $policy = null): Builder
    {
        return Container::getInstance()->make(TaxonomyHostQueryAdapter::class)->filter($query, $taxonomy, $values, 'all', $actor, $policy);
    }

    /**
     * Exclude owners attached to any supplied term reference.
     *
     * @param  Builder<static>  $query
     * @param  list<string|int>  $values
     * @return Builder<static>
     */
    public function scopeWithoutTerms(Builder $query, string $taxonomy, array $values, Authenticatable|SystemActor $actor, ?TaxonomyBatchAuthorization $policy = null): Builder
    {
        return Container::getInstance()->make(TaxonomyHostQueryAdapter::class)->filter($query, $taxonomy, $values, 'without', $actor, $policy);
    }

    /**
     * Restrict owners to a category or any descendant category.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInCategory(Builder $query, Term $category, Authenticatable|SystemActor $actor, bool $includeDescendants = true, ?TaxonomyBatchAuthorization $policy = null): Builder
    {
        return Container::getInstance()->make(TaxonomyHostQueryAdapter::class)->category($query, $category, $actor, $includeDescendants, $policy);
    }

    /**
     * Determine whether this owner has one exact taxonomy term.
     *
     * @internal
     */
    public function hasTerm(string $taxonomy, string|int|Term $value): bool
    {
        $canonicalOwner = Container::getInstance()->make(TaxonomyOwnerRegistry::class)->resolve($this);
        $relation = Str::plural($taxonomy);

        if ($value instanceof Term) {
            $identifier = $value->getRawOriginal($value->getKeyName());
            if (! is_string($identifier)) {
                throw TaxonomyOwnership::violation('A canonical taxonomy term identifier is required.');
            }
            $value = TermModelRegistry::query()->findOrFail($identifier);
        }

        if ($this->relationLoaded($relation)) {
            $collection = $this->getRelation($relation);

            if (! $collection instanceof Collection) {
                return false;
            }

            foreach ($collection as $loadedTerm) {
                if (! $loadedTerm instanceof Term) {
                    throw TaxonomyOwnership::violation('A loaded taxonomy relation contains an invalid term.');
                }
                Container::getInstance()->make(OwnershipBoundary::class)->assertRecord($loadedTerm, 'taxonomy.terms');
                if ($loadedTerm->getRawOriginal('taxonomy') !== $taxonomy) {
                    throw TaxonomyOwnership::violation('A loaded taxonomy relation contains a foreign term.');
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
            $terms = TermModelRegistry::query()->where('taxonomy', $taxonomy);

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
}
