<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use InvalidArgumentException;
use Nvl\Taxonomy\Contracts\TaxonomyTreeContract;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/**
 * Builds a deterministic localized tree for any registered vocabulary.
 *
 * @api
 */
final readonly class TaxonomyTree implements TaxonomyTreeContract
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
        return $this->load($taxonomy, $locale);
    }

    /**
     * Load a complete finite hierarchy for the native catalogue DTO reader.
     *
     * @internal
     *
     * @return Collection<int, Term>
     */
    public function boundedFor(string $taxonomy, ?string $locale, int $limit): Collection
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Taxonomy catalogue tree limit must be between one and five hundred terms.');
        }

        return $this->load($taxonomy, $locale, $limit);
    }

    /**
     * Load the registered model's scoped facts before constructing the hierarchy.
     *
     * @return Collection<int, Term>
     */
    private function load(string $taxonomy, ?string $locale, ?int $limit = null): Collection
    {
        $definition = $this->taxonomies->get($taxonomy);
        $modelClass = $definition->model;
        $query = $modelClass::query()
            ->where('taxonomy', $taxonomy)
            ->ordered($taxonomy);
        if ($limit !== null) {
            $query->withoutEagerLoads()->limit($limit + 1);
        }
        $terms = $query->withResolvedTranslations($locale)->get();
        if ($limit !== null && $terms->count() > $limit) {
            throw new InvalidArgumentException('Taxonomy catalogue exceeds its requested tree limit.');
        }
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
