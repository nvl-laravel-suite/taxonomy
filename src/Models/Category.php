<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Models;

use Illuminate\Support\Collection;
use Nvl\Taxonomy\Concerns\BelongsToTaxonomy;

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
        $terms = static::query()
            ->ordered(static::$taxonomy)
            ->withResolvedTranslations($locale)
            ->get();
        $grouped = [];

        foreach ($terms as $term) {
            $grouped[$term->parent_id ?? '__root__'][] = $term;
        }

        return static::buildTree($grouped, '__root__', []);
    }

    /**
     * @param  array<string, list<static>>  $grouped
     * @param  array<string, true>  $visited
     * @return Collection<int, static>
     */
    protected static function buildTree(
        array $grouped,
        string $parentId,
        array $visited,
    ): Collection {
        $tree = new Collection;

        foreach ($grouped[$parentId] ?? [] as $child) {
            if (isset($visited[$child->id])) {
                continue;
            }

            $branchVisited = $visited;
            $branchVisited[$child->id] = true;
            $child->setRelation(
                'children',
                static::buildTree($grouped, $child->id, $branchVisited),
            );
            $tree->push($child);
        }

        return $tree;
    }
}
