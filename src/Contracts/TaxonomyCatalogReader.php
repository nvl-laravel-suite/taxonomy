<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Contracts;

use Illuminate\Support\Collection;
use Nvl\Taxonomy\Data\TaxonomyTermDisplay;

/**
 * Reads registered vocabulary display facts within the current native ownership scope.
 *
 * @api
 */
interface TaxonomyCatalogReader
{
    /**
     * Return a complete localized hierarchy, refusing vocabularies exceeding the requested limit of one to five hundred terms.
     *
     * @return Collection<int, TaxonomyTermDisplay>
     */
    public function tree(string $taxonomy, ?string $locale = null, int $limit = 500): Collection;

    /** Return one visible localized term without loading its descendants, or null when its identity is not visible. */
    public function term(string $taxonomy, string $id, ?string $locale = null): ?TaxonomyTermDisplay;
}
