<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Contracts;

use Nvl\Taxonomy\Models\Term;

/**
 * Defines the supported term resolver capability.
 *
 * @api
 */
interface TermResolverContract
{
    /**
     * Resolve ordered references and create missing roots when permitted.
     *
     * @param  list<Term|string>  $references
     * @return list<Term>
     */
    public function resolve(string $taxonomy, array $references, bool $createMissing = true): array;
}
