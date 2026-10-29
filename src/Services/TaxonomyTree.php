<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\TermTranslation;
use Nvl\Taxonomy\Ownership\TaxonomyBoundary;
use Nvl\Taxonomy\Ownership\TaxonomyOwnership;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Translatable\Services\ContentLocale;

/**
 * Builds a deterministic localized tree for any registered vocabulary.
 *
 * @internal
 */
final readonly class TaxonomyTree
{
    /**
     * Create the generic taxonomy tree reader.
     */
    public function __construct(
        private TaxonomyRegistry $taxonomies,
        private ContentLocale $contentLocale,
        private TaxonomyBoundary $boundary,
    ) {}

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
     * Load a complete finite hierarchy for native catalogue reads.
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
        $locale ??= $this->contentLocale->get();
        $definition = $this->taxonomies->get($taxonomy);
        $modelClass = $definition->model;
        $prototype = new $modelClass;
        $query = $this->boundary->query($modelClass::query()
            ->where('taxonomy', $taxonomy)
            ->ordered($taxonomy), 'taxonomy.terms');
        if ($limit !== null) {
            $query->limit($limit + 1);
        }
        $query->withoutEagerLoads()->select($prototype->qualifyColumn('*'));
        $translation = new TermTranslation;
        $join = DB::connection($prototype->getConnectionName())
            ->query()->fromSub($query->toBase(), 'nvl_visible_terms')
            ->leftJoin($translation->getTable().' as nvl_tree_copy', function (JoinClause $join): void {
                $join->on('nvl_tree_copy.term_id', '=', 'nvl_visible_terms.id');
                if (TaxonomyOwnership::declared()) {
                    $join->on('nvl_tree_copy.owner_scope_key', '=', 'nvl_visible_terms.owner_scope_key');
                }
            })
            ->select('nvl_visible_terms.*')
            ->addSelect([
                'nvl_tree_copy.id as nvl_translation_id',
                'nvl_tree_copy.locale as nvl_translation_locale',
                'nvl_tree_copy.name as nvl_translation_name',
                'nvl_tree_copy.description as nvl_translation_description',
            ])
            ->orderBy('nvl_visible_terms.parent_key')
            ->orderBy('nvl_visible_terms.position')
            ->orderBy('nvl_visible_terms.id')
            ->orderBy('nvl_tree_copy.locale');
        $termsById = [];
        foreach ($join->get() as $row) {
            /** @var array<string,mixed> $attributes */
            $attributes = get_object_vars($row);
            $id = $attributes['id'] ?? null;
            if (! is_string($id)) {
                throw new \LogicException('A taxonomy tree row has no canonical term identity.');
            }
            if (! isset($termsById[$id])) {
                $structural = array_filter($attributes, static fn (string $key): bool => ! str_starts_with($key, 'nvl_translation_'), ARRAY_FILTER_USE_KEY);
                $term = $prototype->newFromBuilder($structural, $prototype->getConnectionName());
                $term->setRelation('translations', new Collection);
                $termsById[$id] = $term;
            }
            $translationId = $attributes['nvl_translation_id'] ?? null;
            if (is_string($translationId)) {
                $copy = [
                    'id' => $translationId,
                    'term_id' => $id,
                    'locale' => $attributes['nvl_translation_locale'],
                    'name' => $attributes['nvl_translation_name'],
                    'description' => $attributes['nvl_translation_description'],
                ];
                if (TaxonomyOwnership::declared()) {
                    $copy['owner_scope_key'] = $attributes['owner_scope_key'];
                }
                $loadedTranslations = $termsById[$id]->getRelation('translations');
                if (! $loadedTranslations instanceof Collection) {
                    throw new \LogicException('A taxonomy tree must retain its loaded translations.');
                }
                $loadedTranslations->push($translation->newFromBuilder($copy, $translation->getConnectionName()));
            }
        }
        $terms = new Collection(array_values($termsById));
        if ($limit !== null && $terms->count() > $limit) {
            throw new InvalidArgumentException('Taxonomy catalogue exceeds its requested tree limit.');
        }
        foreach ($terms as $term) {
            $term->preloadDisplayCopy($locale);
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
