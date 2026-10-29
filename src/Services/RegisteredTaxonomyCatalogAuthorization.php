<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Contracts\TaxonomyCatalogAuthorization;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/**
 * Preserves registered vocabulary admission while native model scopes retain ownership isolation.
 *
 * @internal
 */
final readonly class RegisteredTaxonomyCatalogAuthorization implements TaxonomyCatalogAuthorization
{
    /** Retain the canonical registry used by the existing tree read capability. */
    public function __construct(private TaxonomyRegistry $taxonomies) {}

    /** Admit only explicitly registered vocabularies. */
    public function authorizeVocabulary(Authenticatable|SystemActor $actor, string $taxonomy): void
    {
        $this->taxonomies->get($taxonomy);
    }
}
