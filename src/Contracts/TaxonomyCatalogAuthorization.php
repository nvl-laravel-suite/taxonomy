<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Contracts;

/**
 * Admits catalogue vocabulary reads without exposing native storage query builders.
 *
 * @api
 */
interface TaxonomyCatalogAuthorization
{
    /** Authorize one vocabulary for the current caller before reading any term facts. */
    public function authorizeVocabulary(string $taxonomy): void;
}
