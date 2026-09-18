<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use InvalidArgumentException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Tenancy\Services\TenantBoundary;

/**
 * Reloads mutation targets through their registered taxonomy model under a row lock.
 */
final readonly class TermModelResolver
{
    /**
     * Create the registered term model resolver.
     */
    public function __construct(
        private TaxonomyRegistry $taxonomies,
        private TenantBoundary $boundary,
    ) {}

    /**
     * Resolve one existing mutation target through its registered model.
     */
    public function lock(Term|string $term): Term
    {
        $id = $term instanceof Term
            ? $term->getRawOriginal($term->getKeyName())
            : $term;

        if (! is_string($id)) {
            throw new InvalidArgumentException('A canonical taxonomy term identifier is required.');
        }

        $baseTerm = Term::query()->lockForUpdate()->findOrFail($id);
        $this->boundary->assertRecord($baseTerm, 'taxonomy.terms');
        $taxonomy = $baseTerm->taxonomy;

        $modelClass = $this->taxonomies->get($taxonomy)->model;

        if ($baseTerm instanceof $modelClass) {
            return $baseTerm;
        }

        $resolved = $modelClass::query()->where('taxonomy', $taxonomy)->lockForUpdate()->findOrFail($id);
        $this->boundary->assertRecord($resolved, 'taxonomy.terms');

        return $resolved;
    }
}
