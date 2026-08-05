<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/**
 * Builds a deterministic localized tree for any registered vocabulary.
 */
final readonly class TaxonomyTree
{
    /**
     * Create the generic taxonomy tree reader.
     */
    public function __construct(private TaxonomyRegistry $taxonomies) {}

    /**
     * Return one registered vocabulary as a deterministic localized tree.
     *
     * @return Collection<int, Term>
     */
    public function for(string $taxonomy, ?string $locale = null): Collection
    {
        $definition = $this->taxonomies->get($taxonomy);
        $modelClass = $definition->model;
        $terms = $modelClass::query()
            ->where('taxonomy', $taxonomy)
            ->ordered($taxonomy)
            ->withResolvedTranslations($locale)
            ->get();
        $grouped = $terms->toBase()->groupBy(
            static fn (Term $term): string => $term->parent_id ?? '__root__',
        );

        return $this->children($grouped, '__root__', []);
    }

    /**
     * @param  BaseCollection<string, BaseCollection<int, Term>>  $grouped
     * @param  array<string, true>  $visited
     * @return Collection<int, Term>
     */
    private function children(BaseCollection $grouped, string $parentId, array $visited): Collection
    {
        $children = $grouped->get($parentId, new BaseCollection);
        $tree = new Collection;

        foreach ($children as $child) {
            if (isset($visited[$child->id])) {
                continue;
            }

            $branchVisited = $visited;
            $branchVisited[$child->id] = true;
            $child->setRelation(
                'children',
                $this->children($grouped, $child->id, $branchVisited),
            );
            $tree->push($child);
        }

        return $tree;
    }
}
