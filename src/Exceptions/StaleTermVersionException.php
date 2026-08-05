<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Exceptions;

/**
 * Raised when a term mutation targets an outdated revision.
 */
final class StaleTermVersionException extends TaxonomyException
{
    /**
     * Create a stale optimistic-revision failure for one term.
     */
    public static function forTerm(string $id): self
    {
        return new self("Taxonomy term [{$id}] changed after it was read.");
    }
}
