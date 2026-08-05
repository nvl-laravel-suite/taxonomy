<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use InvalidArgumentException;
use Nvl\Taxonomy\Exceptions\CircularHierarchyException;
use Nvl\Taxonomy\Exceptions\DuplicateSiblingSlugException;
use Nvl\Taxonomy\Exceptions\FlatVocabularyException;
use Nvl\Taxonomy\Exceptions\InvalidParentException;
use Nvl\Taxonomy\Exceptions\MaximumDepthExceededException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/**
 * Validates parent membership, cycles, and configured tree depth.
 */
final readonly class TermHierarchy
{
    /**
     * Create the registered hierarchy validator.
     */
    public function __construct(private TaxonomyRegistry $taxonomies) {}

    /**
     * Validate one parent placement and prospective subtree depth.
     */
    public function validate(
        string $taxonomy,
        ?string $parentId,
        ?string $termId = null,
        int $subtreeDepth = 1,
    ): void {
        $definition = $this->taxonomies->get($taxonomy);

        if ($parentId !== null && ! $definition->hierarchical) {
            throw FlatVocabularyException::forTaxonomy($taxonomy);
        }

        $terms = $this->lockedTerms($taxonomy);

        $this->validateWithTerms($taxonomy, $parentId, $termId, $subtreeDepth, $terms);
    }

    /**
     * Validate one or more subtree moves from a single locked vocabulary snapshot.
     *
     * @param  Collection<int, Term>  $movingTerms
     */
    public function validateMoves(Collection $movingTerms, ?string $parentId): void
    {
        $first = $movingTerms->first();

        if (! $first instanceof Term) {
            return;
        }

        $taxonomy = $first->taxonomy;
        $definition = $this->taxonomies->get($taxonomy);

        if ($parentId !== null && ! $definition->hierarchical) {
            throw FlatVocabularyException::forTaxonomy($taxonomy);
        }

        $terms = $this->lockedTerms($taxonomy);
        $movingIds = [];

        foreach ($movingTerms as $movingTerm) {
            if ($movingTerm->taxonomy !== $taxonomy || ! $terms->has($movingTerm->id)) {
                throw new InvalidArgumentException(
                    'Every moved term must exist in the same registered taxonomy.',
                );
            }

            $movingIds[$movingTerm->id] = true;
        }

        $destinationKey = $parentId ?? '__root__';
        $occupiedSlugs = [];

        foreach ($terms as $term) {
            if ($term->parent_key === $destinationKey && ! isset($movingIds[$term->id])) {
                $occupiedSlugs[$term->slug] = true;
            }
        }

        $movingSlugs = [];

        foreach (array_keys($movingIds) as $movingId) {
            $movingTerm = $terms->get($movingId);

            if (! $movingTerm instanceof Term) {
                throw new InvalidArgumentException('A moved taxonomy term no longer exists.');
            }

            if (isset($occupiedSlugs[$movingTerm->slug])
                || isset($movingSlugs[$movingTerm->slug])) {
                throw DuplicateSiblingSlugException::forSlug($taxonomy, $movingTerm->slug);
            }

            $movingSlugs[$movingTerm->slug] = true;
            $subtreeDepth = $this->subtreeDepthFromTerms($movingTerm, $terms);
            $this->validateWithTerms(
                $taxonomy,
                $parentId,
                $movingTerm->id,
                $subtreeDepth,
                $terms,
            );
        }
    }

    /**
     * Return the number of levels occupied by a term and its deepest descendant.
     */
    public function subtreeDepth(Term $term): int
    {
        return $this->subtreeDepthFromTerms($term, $this->lockedTerms($term->taxonomy));
    }

    /**
     * Return deterministic descendants from a locked vocabulary snapshot.
     *
     * @return Collection<int, Term>
     */
    public function descendants(Term $term): Collection
    {
        $terms = $this->lockedTerms($term->taxonomy);
        $children = $terms->toBase()->groupBy(
            static fn (Term $candidate): string => $candidate->parent_id ?? '__root__',
        );
        $descendants = new Collection;
        $visited = [$term->id => true];
        $append = function (string $parentId) use (
            &$append,
            $children,
            $descendants,
            &$visited,
        ): void {
            $directChildren = $children->get($parentId, new BaseCollection);

            foreach ($directChildren as $child) {
                if (isset($visited[$child->id])) {
                    throw new CircularHierarchyException(
                        'A taxonomy hierarchy cannot contain a cycle.',
                    );
                }

                $visited[$child->id] = true;
                $descendants->push($child);
                $append($child->id);
            }
        };
        $append($term->id);

        return $descendants;
    }

    /**
     * @param  Collection<string, Term>  $terms
     */
    private function validateWithTerms(
        string $taxonomy,
        ?string $parentId,
        ?string $termId,
        int $subtreeDepth,
        Collection $terms,
    ): void {
        $definition = $this->taxonomies->get($taxonomy);
        $candidateDepth = $subtreeDepth;

        if ($parentId !== null) {
            $parent = $terms->get($parentId);

            if (! $parent instanceof Term) {
                throw InvalidParentException::forTerm($taxonomy, $parentId);
            }

            $candidateDepth += $this->depthOf($parent, $terms, $termId);
        }

        if ($definition->maxDepth > 0 && $candidateDepth > $definition->maxDepth) {
            throw MaximumDepthExceededException::forTaxonomy(
                $taxonomy,
                $definition->maxDepth,
            );
        }
    }

    /**
     * @param  Collection<string, Term>  $terms
     */
    private function subtreeDepthFromTerms(Term $term, Collection $terms): int
    {
        $children = $terms->toBase()->groupBy(
            static fn (Term $candidate): string => $candidate->parent_id ?? '__root__',
        );

        return $this->descendantDepth($term->id, $children, []);
    }

    /**
     * @return Collection<string, Term>
     */
    private function lockedTerms(string $taxonomy): Collection
    {
        $definition = $this->taxonomies->get($taxonomy);

        return $definition->model::query()
            ->where('taxonomy', $taxonomy)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<string, Term>  $terms
     */
    private function depthOf(Term $term, Collection $terms, ?string $movingTermId): int
    {
        $depth = 1;
        $visited = [];
        $cursor = $term;

        while (true) {
            if ($cursor->id === $movingTermId || isset($visited[$cursor->id])) {
                throw new CircularHierarchyException('A taxonomy hierarchy cannot contain a cycle.');
            }

            $visited[$cursor->id] = true;

            if ($cursor->parent_id === null) {
                return $depth;
            }

            $parent = $terms->get($cursor->parent_id);

            if (! $parent instanceof Term) {
                throw InvalidParentException::forTerm($cursor->taxonomy, $cursor->parent_id);
            }

            $cursor = $parent;
            $depth++;
        }
    }

    /**
     * @param  BaseCollection<string, BaseCollection<int, Term>>  $children
     * @param  array<string, true>  $visited
     */
    private function descendantDepth(string $termId, BaseCollection $children, array $visited): int
    {
        if (isset($visited[$termId])) {
            throw new CircularHierarchyException('A taxonomy hierarchy cannot contain a cycle.');
        }

        $visited[$termId] = true;
        $maximumChildDepth = 0;
        $directChildren = $children->get($termId, new BaseCollection);

        foreach ($directChildren as $child) {
            $maximumChildDepth = max(
                $maximumChildDepth,
                $this->descendantDepth($child->id, $children, $visited),
            );
        }

        return 1 + $maximumChildDepth;
    }
}
