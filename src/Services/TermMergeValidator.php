<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use InvalidArgumentException;
use Nvl\Taxonomy\Exceptions\StaleTermVersionException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Taxonomy\Support\TermMergeContext;

/**
 * Resolves and validates a merge from locked term and hierarchy snapshots.
 */
final readonly class TermMergeValidator
{
    /**
     * Create the merge validator.
     */
    public function __construct(
        private TermHierarchy $hierarchy,
        private TaxonomyRegistry $taxonomies,
    ) {}

    /**
     * Resolve a valid merge context at explicit optimistic revisions.
     */
    public function validate(
        string $sourceId,
        string $destinationId,
        int $expectedSourceRevision,
        int $expectedDestinationRevision,
    ): TermMergeContext {
        $terms = Term::query()
            ->whereKey([$sourceId, $destinationId])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $source = $terms->get($sourceId);
        $destination = $terms->get($destinationId);

        if (! $source instanceof Term || ! $destination instanceof Term) {
            throw new InvalidArgumentException('Both merge terms must exist.');
        }

        if ($source->id === $destination->id || $source->taxonomy !== $destination->taxonomy) {
            throw new InvalidArgumentException(
                'Merge terms must be distinct members of the same taxonomy.',
            );
        }

        $modelClass = $this->taxonomies->get($source->taxonomy)->model;

        if ($modelClass !== Term::class) {
            $typedTerms = $modelClass::query()
                ->whereKey([$sourceId, $destinationId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $source = $typedTerms->get($sourceId);
            $destination = $typedTerms->get($destinationId);

            if (! $source instanceof Term || ! $destination instanceof Term) {
                throw new InvalidArgumentException(
                    'Both merge terms must resolve through the registered taxonomy model.',
                );
            }
        }

        if ($source->revision !== $expectedSourceRevision) {
            throw StaleTermVersionException::forTerm($source->id);
        }

        if ($destination->revision !== $expectedDestinationRevision) {
            throw StaleTermVersionException::forTerm($destination->id);
        }

        $children = $source->children()
            ->lockForUpdate()
            ->get();

        $this->hierarchy->validateMoves($children, $destination->id);

        return new TermMergeContext($source, $destination, $children);
    }
}
