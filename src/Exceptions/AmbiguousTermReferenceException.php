<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Exceptions;

/**
 * Raised when a slug identifies more than one term in a hierarchical vocabulary.
 */
final class AmbiguousTermReferenceException extends TaxonomyException
{
    /**
     * Create an ambiguity failure for one taxonomy slug.
     */
    public static function forSlug(string $taxonomy, string $slug): self
    {
        return new self(
            "Term slug [{$slug}] is ambiguous in taxonomy [{$taxonomy}]; use a term UUID.",
        );
    }
}
