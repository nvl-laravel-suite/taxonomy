<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Exceptions;

/**
 * Raised when siblings would share the same canonical slug.
 */
final class DuplicateSiblingSlugException extends TaxonomyException
{
    /**
     * Create a duplicate sibling failure.
     */
    public static function forSlug(string $taxonomy, string $slug): self
    {
        return new self(
            "Sibling slug [{$slug}] already exists in taxonomy [{$taxonomy}].",
        );
    }
}
