<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Concerns;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Support\TaxonomyDefinition;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/**
 * Applies one immutable configured vocabulary scope to a specialized term model.
 */
trait BelongsToTaxonomy
{
    /**
     * Apply the configured vocabulary scope, creation default, and mutation guard.
     */
    public static function bootBelongsToTaxonomy(): void
    {
        static::addGlobalScope('taxonomy', fn (Builder $query): Builder => $query->where(
            $query->getModel()->qualifyColumn('taxonomy'), static::$taxonomy
        ));

        static::saving(function (Term $term): void {
            $taxonomy = $term->getAttribute('taxonomy');

            if ((! is_string($taxonomy) || $taxonomy === '') && ! $term->exists) {
                $term->taxonomy = static::$taxonomy;

                return;
            }

            if ($taxonomy !== static::$taxonomy) {
                throw new InvalidArgumentException(
                    'Specialized taxonomy models cannot change vocabularies.',
                );
            }
        });
    }

    /**
     * Return the immutable vocabulary alias for this specialized model.
     */
    public static function taxonomyName(): string
    {
        return static::$taxonomy;
    }

    /**
     * Return this model's registered vocabulary definition.
     */
    public function definition(): TaxonomyDefinition
    {
        return app(TaxonomyRegistry::class)->get(static::$taxonomy);
    }
}
