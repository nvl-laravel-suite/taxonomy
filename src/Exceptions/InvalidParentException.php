<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Exceptions;

/**
 * Raised when a requested parent is missing or belongs to another vocabulary.
 */
final class InvalidParentException extends TaxonomyException
{
    /**
     * Create an invalid parent failure.
     */
    public static function forTerm(string $taxonomy, string $parentId): self
    {
        return new self(
            "Parent [{$parentId}] does not belong to taxonomy [{$taxonomy}].",
        );
    }
}
