<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Taxonomy\Enums\TermChangeOperation;
use Nvl\Taxonomy\Events\TermChanged;
use Nvl\Taxonomy\Exceptions\StaleTermVersionException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TermHierarchy;
use Nvl\Taxonomy\Services\TermModelResolver;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

/**
 * Reparents one term after locked cycle and depth validation.
 */
final readonly class MoveTermAction
{
    /**
     * Create the term move action.
     */
    public function __construct(
        private TermHierarchy $hierarchy,
        private TermModelResolver $terms,
    ) {}

    /**
     * Move one subtree to a validated parent and sibling position.
     */
    public function execute(
        Term|string $term,
        ?string $parentId,
        int $position,
        int $expectedRevision,
    ): Term {
        if ($position < 0) {
            throw new InvalidArgumentException('Taxonomy term positions cannot be negative.');
        }

        $connection = $term instanceof Term
            ? $term->getConnectionName()
            : (new Term)->getConnectionName();

        return DB::connection($connection)->transaction(function () use (
            $term,
            $parentId,
            $position,
            $expectedRevision,
        ): Term {
            $term = $this->terms->lock($term);

            if ($term->revision !== $expectedRevision) {
                throw StaleTermVersionException::forTerm($term->id);
            }

            $this->hierarchy->validateMoves(new Collection([$term]), $parentId);

            $term->parent_id = $parentId;
            $term->position = $position;
            $term->save();
            TermChanged::dispatch(
                $term->id,
                $term->taxonomy,
                TermChangeOperation::Moved,
                $term->revision,
            );

            return $term->refresh()->load('translations');
        }, TaxonomyConfiguration::transactionAttempts());
    }
}
