<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Models;

use Nvl\Taxonomy\Concerns\BelongsToTaxonomy;

/**
 * Convenience flat term model for the built-in tag vocabulary.
 */
class Tag extends Term
{
    use BelongsToTaxonomy;

    protected static string $taxonomy = 'tag';
}
