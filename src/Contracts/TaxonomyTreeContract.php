<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Nvl\Taxonomy\Models\Term;

/**
 * Defines the supported taxonomy tree capability.
 *
 * @api
 */
interface TaxonomyTreeContract
{
    /**
     * Return one registered vocabulary as a deterministic localized tree.
     *
     * @return Collection<int, Term>
     */
    public function for(string $taxonomy, ?string $locale = null): Collection;
}
