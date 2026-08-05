<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Exceptions;

/**
 * Raised when hierarchy is requested for a flat vocabulary.
 */
final class FlatVocabularyException extends TaxonomyException
{
    /**
     * Create a flat-vocabulary hierarchy failure.
     */
    public static function forTaxonomy(string $taxonomy): self
    {
        return new self("Taxonomy [{$taxonomy}] does not allow parent terms.");
    }
}
