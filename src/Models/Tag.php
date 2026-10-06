<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Models;

use Nvl\Taxonomy\Concerns\BelongsToTaxonomy;
use Nvl\Taxonomy\Database\Factories\TermFactory;

/**
 * Convenience flat term model for the built-in tag vocabulary.
 */
class Tag extends Term
{
    use BelongsToTaxonomy;

    protected static string $taxonomy = 'tag';

    /** Return a Term fixture in the specialized native vocabulary.
     *
     * @internal
     */
    protected static function newFactory(): TermFactory
    {
        return TermFactory::new()->tag();
    }
}
