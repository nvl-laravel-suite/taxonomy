<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/**
 * Reloads mutation targets through their registered taxonomy model under a row lock.
 */
final readonly class TermModelResolver
{
    /**
     * Create the registered term model resolver.
     */
    public function __construct(private TaxonomyRegistry $taxonomies) {}

    /**
     * Resolve one existing mutation target through its registered model.
     */
    public function lock(Term|string $term): Term
    {
        $id = $term instanceof Term ? $term->id : $term;
        $taxonomy = $term instanceof Term ? $term->taxonomy : null;
        $baseTerm = null;

        if ($taxonomy === null) {
            $baseTerm = Term::query()->lockForUpdate()->findOrFail($id);
            $taxonomy = $baseTerm->taxonomy;
        }

        $modelClass = $this->taxonomies->get($taxonomy)->model;

        if ($baseTerm instanceof $modelClass) {
            return $baseTerm;
        }

        return $modelClass::query()->lockForUpdate()->findOrFail($id);
    }
}
