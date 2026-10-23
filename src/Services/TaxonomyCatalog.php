<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Taxonomy\Contracts\TaxonomyCatalogAuthorization;
use Nvl\Taxonomy\Contracts\TaxonomyCatalogReader;
use Nvl\Taxonomy\Data\TaxonomyTermDisplay;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Translatable\Services\ContentLocale;

/** Projects authorized registered vocabulary facts without exposing mutable model handles. */
final readonly class TaxonomyCatalog implements TaxonomyCatalogReader
{
    /** Retain canonical vocabulary, tree, caller admission and locale services. */
    public function __construct(
        private TaxonomyRegistry $taxonomies,
        private TaxonomyTree $trees,
        private TaxonomyCatalogAuthorization $authorization,
        private ContentLocale $locale,
    ) {}

    /**
     * Return a complete bounded localized hierarchy under the native term scopes.
     *
     * @return Collection<int, TaxonomyTermDisplay>
     */
    public function tree(string $taxonomy, ?string $locale = null, int $limit = 500): Collection
    {
        $this->authorization->authorizeVocabulary($taxonomy);
        $locale ??= $this->locale->get();

        return $this->trees->boundedFor($taxonomy, $locale, $limit)->toBase()
            ->map(fn (Term $term): TaxonomyTermDisplay => $this->project($term, $locale));
    }

    /** Return one scoped localized term identity without reading its descendants. */
    public function term(string $taxonomy, string $id, ?string $locale = null): ?TaxonomyTermDisplay
    {
        $this->authorization->authorizeVocabulary($taxonomy);
        $definition = $this->taxonomies->get($taxonomy);
        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException('Taxonomy catalogue term identities must be UUIDs.');
        }
        $locale ??= $this->locale->get();
        $term = $definition->model::query()
            ->withoutEagerLoads()
            ->where('taxonomy', $taxonomy)
            ->whereKey($id)
            ->withResolvedTranslations($locale)
            ->first();

        return $term === null ? null : $this->project($term, $locale);
    }

    /** Project only the eager-loaded hierarchy and resolved translated values. */
    private function project(Term $term, string $locale): TaxonomyTermDisplay
    {
        $projectedChildren = [];
        if ($term->relationLoaded('children')) {
            foreach ($term->children as $child) {
                $projectedChildren[] = $this->project($child, $locale);
            }
        }

        return new TaxonomyTermDisplay(
            id: $term->id,
            vocabulary: $term->taxonomy,
            slug: $term->slug,
            parentId: $term->parent_id,
            position: $term->position,
            revision: $term->revision,
            name: $term->displayName($locale),
            description: $term->displayDescription($locale),
            meta: $term->meta,
            children: $projectedChildren,
        );
    }
}
