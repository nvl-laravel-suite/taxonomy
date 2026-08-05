<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Exceptions;

/**
 * Raised when a deletion strategy cannot preserve taxonomy invariants.
 */
final class UnsafeTermDeletionException extends TaxonomyException
{
    /**
     * Create an unsafe deletion failure.
     */
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
