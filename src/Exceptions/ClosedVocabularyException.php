<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Exceptions;

/**
 * Raised when unknown term input is supplied to a closed vocabulary.
 */
class ClosedVocabularyException extends TaxonomyException
{
    /**
     * @param  list<string>  $missingSlugs
     */
    public function __construct(string $taxonomy, array $missingSlugs)
    {
        $missing = implode(', ', $missingSlugs);
        parent::__construct("Taxonomy [$taxonomy] is closed. The following terms cannot be created on the fly: [$missing].");
    }
}
