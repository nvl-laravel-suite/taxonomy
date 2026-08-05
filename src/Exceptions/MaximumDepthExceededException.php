<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Exceptions;

/**
 * Raised when a placement would exceed a vocabulary's maximum tree depth.
 */
final class MaximumDepthExceededException extends TaxonomyException
{
    /**
     * Create a maximum-depth failure.
     */
    public static function forTaxonomy(string $taxonomy, int $maximumDepth): self
    {
        return new self(
            "Taxonomy [{$taxonomy}] allows at most {$maximumDepth} levels.",
        );
    }
}
