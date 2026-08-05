<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Taxonomy\Enums\DeleteTermStrategy;
use Nvl\Taxonomy\Enums\TermChangeOperation;
use Nvl\Taxonomy\Events\TermChanged;
use Nvl\Taxonomy\Exceptions\CircularHierarchyException;
use Nvl\Taxonomy\Exceptions\StaleTermVersionException;
use Nvl\Taxonomy\Exceptions\UnsafeTermDeletionException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TermHierarchy;
use Nvl\Taxonomy\Services\TermModelResolver;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

/**
 * Deletes a term through an explicit attachment/child handling strategy.
 */
final readonly class DeleteTermAction
{
    /**
     * Create the term deletion action.
     */
    public function __construct(
        private TermHierarchy $hierarchy,
        private TermModelResolver $terms,
    ) {}

    /**
     * Delete one term at an explicit revision and handling strategy.
     */
    public function execute(
        Term|string $term,
        int $expectedRevision,
        DeleteTermStrategy $strategy = DeleteTermStrategy::Restrict,
        ?string $reparentTo = null,
    ): bool {
        $connection = $term instanceof Term
            ? $term->getConnectionName()
            : (new Term)->getConnectionName();

        return DB::connection($connection)->transaction(function () use (
            $term,
            $expectedRevision,
            $strategy,
            $reparentTo,
        ): bool {
            $term = $this->terms->lock($term);

            if ($term->revision !== $expectedRevision) {
                throw StaleTermVersionException::forTerm($term->id);
            }

            $hasAttachments = $term->attachments()->exists();
            $hasChildren = $term->children()->exists();

            if ($strategy === DeleteTermStrategy::Restrict && ($hasAttachments || $hasChildren)) {
                throw UnsafeTermDeletionException::because(
                    'Attached terms or terms with children require an explicit deletion strategy.',
                );
            }

            if ($strategy === DeleteTermStrategy::Reparent) {
                if ($reparentTo === $term->id) {
                    throw new CircularHierarchyException(
                        'A deleted term cannot be its own reparenting destination.',
                    );
                }

                $children = $term->children()
                    ->lockForUpdate()
                    ->get();

                $this->hierarchy->validateMoves($children, $reparentTo);

                foreach ($children as $child) {
                    $child->parent_id = $reparentTo;
                    $child->save();
                    TermChanged::dispatch(
                        $child->id,
                        $child->taxonomy,
                        TermChangeOperation::Moved,
                        $child->revision,
                    );
                }
            } elseif ($strategy === DeleteTermStrategy::Cascade) {
                foreach ($this->hierarchy->descendants($term)->reverse() as $descendant) {
                    $descendant->attachments()->delete();
                    TermChanged::dispatch(
                        $descendant->id,
                        $descendant->taxonomy,
                        TermChangeOperation::Deleted,
                        $descendant->revision,
                    );
                    $descendant->delete();
                }
            } elseif ($hasChildren) {
                throw UnsafeTermDeletionException::because(
                    'The selected deletion strategy does not handle child terms.',
                );
            }

            if ($strategy !== DeleteTermStrategy::Restrict) {
                $term->attachments()->delete();
            }

            TermChanged::dispatch(
                $term->id,
                $term->taxonomy,
                TermChangeOperation::Deleted,
                $term->revision,
            );

            return (bool) $term->delete();
        }, TaxonomyConfiguration::transactionAttempts());
    }
}
