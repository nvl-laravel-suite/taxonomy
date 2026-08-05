<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Support;

use Nvl\Taxonomy\Models\Term;

/**
 * Immutable normalized contract for one registered taxonomy vocabulary.
 */
final readonly class TaxonomyDefinition
{
    /**
     * Create one immutable normalized vocabulary definition.
     *
     * @param  class-string<Term>  $model
     * @param  list<string>  $allowedOwners
     * @param  array<string, mixed>  $metadataRules
     */
    public function __construct(
        public string $taxonomy,
        public string $model,
        public bool $hierarchical,
        public bool $exclusive,
        public bool $open,
        public int $maxDepth,
        public string $sort,
        public array $allowedOwners,
        public array $metadataRules,
    ) {}
}
