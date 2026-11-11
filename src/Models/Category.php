<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Models;

use Illuminate\Support\Collection;
use Nvl\Taxonomy\Concerns\BelongsToTaxonomy;
use Nvl\Taxonomy\Database\Factories\TermFactory;
use Nvl\Taxonomy\Services\TaxonomyTree;

/**
 * Convenience hierarchical term model for the built-in category vocabulary.
 */
class Category extends Term
{
    use BelongsToTaxonomy;

    protected static string $taxonomy = 'category';

    /**
     * Build the ordered category tree with translations resolved eagerly.
     *
     * @return Collection<int, static>
     */
    public static function tree(?string $locale = null): Collection
    {
        return TaxonomyTree::nest(
            static::query()
                ->ordered(static::$taxonomy)
                ->withResolvedTranslations($locale)
                ->get(),
        )->toBase();
    }

    /**
     * Return a Term fixture in the specialized native vocabulary.
     *
     * @internal
     */
    protected static function newFactory(): TermFactory
    {
        return TermFactory::new()->category();
    }
}
