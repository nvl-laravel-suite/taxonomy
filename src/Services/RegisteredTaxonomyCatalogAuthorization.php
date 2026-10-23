<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Nvl\Taxonomy\Contracts\TaxonomyCatalogAuthorization;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/** Preserves registered vocabulary admission while native model scopes retain ownership isolation. */
final readonly class RegisteredTaxonomyCatalogAuthorization implements TaxonomyCatalogAuthorization
{
    /** Retain the canonical registry used by the existing tree read capability. */
    public function __construct(private TaxonomyRegistry $taxonomies) {}

    /** Admit only explicitly registered vocabularies. */
    public function authorizeVocabulary(string $taxonomy): void
    {
        $this->taxonomies->get($taxonomy);
    }
}
