<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipModelPersistence;
use Nvl\Taxonomy\Enums\TaxonomyMembershipMode;
use Nvl\Taxonomy\Enums\TaxonomyMembershipOperation;
use Nvl\Taxonomy\Exceptions\InvalidTermOperationException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Ownership\TaxonomyBoundary as OwnershipBoundary;
use Nvl\Taxonomy\Ownership\TaxonomyOwnership;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyDefinition;
use Nvl\Taxonomy\Support\TaxonomyMembershipProposal;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/**
 * Maintains one owner's ordered taxonomy attachment set inside caller transactions.
 *
 * @internal
 */
final readonly class TermAttachmentWriter
{
    /**
     * Create the transaction-agnostic attachment writer.
     */
    public function __construct(
        private TaxonomyRegistry $taxonomies,
        private TaxonomyOwnerRegistry $owners,
        private TermResolver $terms,
        private OwnershipBoundary $boundary,
        private OwnershipModelPersistence $persistence,
        private TaxonomyMembershipGuard $guard,
    ) {}

    /**
     * Replace one owner's complete ordered vocabulary attachment set and clear provider sources on every row.
     *
     * @param  list<Term|string>  $references
     */
    public function sync(Model $owner, string $taxonomy, array $references, Authenticatable|SystemActor $actor): void
    {
        $this->persist($owner, function (Model $canonical) use ($taxonomy, $references, $actor): void {
            $this->syncRecords($canonical, $taxonomy, $references, $actor, TaxonomyMembershipOperation::Sync);
        });
    }

    /**
     * Review a provider proposal and apply it, stamped with its source, only when the policy answers Apply.
     *
     * A shadow decision writes nothing and creates no terms.
     */
    public function reconcile(Model $owner, string $taxonomy, TaxonomyMembershipProposal $proposal, Authenticatable|SystemActor $actor): TaxonomyMembershipMode
    {
        $mode = TaxonomyMembershipMode::Shadow;
        $this->persist($owner, function (Model $canonical) use ($taxonomy, $proposal, $actor, &$mode): void {
            $mode = $this->syncRecords($canonical, $taxonomy, $proposal->terms, $actor, TaxonomyMembershipOperation::Reconcile, $proposal->source);
        });

        return $mode;
    }

    public function assertOwnerCapability(Model $owner, string $taxonomy): void
    {
        $this->context($owner, $taxonomy, lock: true);
    }

    /** Run one write under the owner's actual host source lease when ownership is declared.
     * @param  \Closure(Model): void  $write
     */
    private function persist(Model $owner, \Closure $write): void
    {
        if (! TaxonomyOwnership::declared()) {
            $write($owner);

            return;
        }
        $canonical = $this->owners->resolve($owner, lock: true);
        $this->persistence->save($canonical, static function () use ($canonical, $write): bool {
            $write($canonical);

            return true;
        });
    }

    /** Review and persist the admitted attachment set and its exact provenance.
     *
     * Every written row gets $source (null clears it), except retained rows outside $touched, which keep theirs.
     *
     * Manual operations write a null source; reconciliation writes the provider's source.
     *
     * @param  list<Term|string>  $references  The complete resulting set.
     * @param  list<Term|string>|null  $touched  References whose rows the write relabels; null relabels every row.
     */
    private function syncRecords(
        Model $owner, string $taxonomy, array $references, Authenticatable|SystemActor $actor,
        TaxonomyMembershipOperation $operation, string $proposalSource = TaxonomyMembershipGuard::ManualSource, ?array $touched = null,
    ): TaxonomyMembershipMode {
        [$definition, $ownerAlias, $ownerId, $canonical] = $this->context($owner, $taxonomy, lock: true);
        $this->assertBulkLimit($references);
        $manual = $operation->isManual();
        $reviewed = $manual ? $this->terms->existingReferences($taxonomy, $references, $actor) : $references;
        $mode = $this->guard->review($canonical, $definition, $actor, new TaxonomyMembershipProposal($proposalSource, $reviewed, $operation));
        if ($mode !== TaxonomyMembershipMode::Apply) {
            return $mode;
        }
        $source = $manual ? null : $proposalSource;
        $resolved = $this->terms->resolve($taxonomy, $references, $actor);

        if ($definition->exclusive && count($resolved) > 1) {
            throw new InvalidTermOperationException(
                "Taxonomy [{$taxonomy}] allows only one term per owner.",
            );
        }

        $ids = array_map(static fn (Term $term): string => $term->id, $resolved);
        $relabeled = $touched === null ? null : array_flip(array_map(
            static fn (Term $term): string => $term->id,
            $this->terms->resolve($taxonomy, $touched, $actor, createMissing: false),
        ));
        $query = $this->ownerQuery($ownerAlias, $ownerId, $taxonomy);
        $existing = [];
        foreach ((clone $query)->lockForUpdate()->get(['id', 'term_id', 'position', 'membership_source']) as $attachment) {
            $position = filter_var($attachment->position, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if (! is_string($attachment->id) || ! is_string($attachment->term_id) || ! is_int($position)
                || ($attachment->membership_source !== null && ! is_string($attachment->membership_source))) {
                throw new InvalidTermOperationException('Stored taxonomy attachments must have canonical identifiers.');
            }
            $existing[$attachment->term_id] = ['id' => $attachment->id, 'position' => $position, 'membership_source' => $attachment->membership_source];
        }

        if ($ids === []) {
            $query->delete();

            return $mode;
        }

        $removed = array_diff(array_keys($existing), $ids);
        if ($removed !== []) {
            (clone $query)->whereIn('term_id', $removed)->delete();
        }

        $now = now();
        $rows = [];

        foreach ($ids as $position => $termId) {
            if (isset($existing[$termId])) {
                $rowSource = $relabeled === null || isset($relabeled[$termId]) ? $source : $existing[$termId]['membership_source'];
                if ($existing[$termId]['position'] !== $position || $existing[$termId]['membership_source'] !== $rowSource) {
                    (clone $query)->where('id', $existing[$termId]['id'])
                        ->update(['position' => $position, 'membership_source' => $rowSource, 'updated_at' => $now]);
                }

                continue;
            }
            $rows[] = [
                'id' => (string) Str::uuid(),
                ...(TaxonomyOwnership::declared() ? $this->boundary->attributes('taxonomy.attachments') : []),
                'term_id' => $termId,
                'termable_type' => $ownerAlias,
                'termable_id' => $ownerId,
                'taxonomy' => $taxonomy,
                'position' => $position,
                'membership_source' => $source,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::connection(TermModelRegistry::newInstance()->getConnectionName())
                ->table((new Termable)->getTable())
                ->insert($rows);
        }

        return $mode;
    }

    /**
     * Append unique terms while preserving the existing attachment order and the sources of untouched rows.
     *
     * @param  list<Term|string>  $references
     */
    public function append(Model $owner, string $taxonomy, array $references, Authenticatable|SystemActor $actor): void
    {
        $this->persist($owner, function (Model $canonical) use ($taxonomy, $references, $actor): void {
            [, $ownerAlias, $ownerId] = $this->context($canonical, $taxonomy, lock: true);
            $current = $this->ownerQuery($ownerAlias, $ownerId, $taxonomy)
                ->orderBy('position')
                ->orderBy('term_id')
                ->pluck('term_id')
                ->filter(static fn (mixed $id): bool => is_string($id))
                ->values()
                ->all();
            $this->syncRecords($canonical, $taxonomy, [...$current, ...$references], $actor, TaxonomyMembershipOperation::Attach, touched: $references);
        });
    }

    /**
     * Remove selected terms, or the complete set when none are supplied.
     *
     * @param  list<Term|string>  $references
     */
    public function detach(Model $owner, string $taxonomy, array $references, Authenticatable|SystemActor $actor): int
    {
        $deleted = 0;
        $this->persist($owner, function (Model $canonical) use ($taxonomy, $references, $actor, &$deleted): void {
            $deleted = $this->detachRecords($canonical, $taxonomy, $references, $actor);
        });

        return $deleted;
    }

    /** Remove admitted attachments while the source lease remains active; survivors keep their sources.
     * @param  list<Term|string>  $references
     */
    private function detachRecords(Model $owner, string $taxonomy, array $references, Authenticatable|SystemActor $actor): int
    {
        [$definition, $ownerAlias, $ownerId, $canonical] = $this->context($owner, $taxonomy, lock: true);
        $query = $this->ownerQuery($ownerAlias, $ownerId, $taxonomy);
        $ids = [];

        if ($references !== []) {
            $this->assertBulkLimit($references);
            $ids = array_map(
                static fn (Term $term): string => $term->id,
                $this->terms->resolve($taxonomy, $references, $actor, createMissing: false),
            );

            if ($ids === []) {
                return 0;
            }
        }

        $current = (clone $query)->lockForUpdate()->orderBy('position')->orderBy('term_id')->pluck('term_id')
            ->filter(static fn (mixed $id): bool => is_string($id))->values()->all();
        $resulting = $references === [] ? [] : array_values(array_diff($current, $ids));
        $this->guard->review($canonical, $definition, $actor, new TaxonomyMembershipProposal(TaxonomyMembershipGuard::ManualSource, $resulting, TaxonomyMembershipOperation::Detach));

        if ($ids !== []) {
            $query->whereIn('term_id', $ids);
        }

        return $query->delete();
    }

    /**
     * @return array{TaxonomyDefinition, string, string, Model}
     */
    private function context(Model $owner, string $taxonomy, bool $lock = false): array
    {
        if (! $owner->exists || $owner->getKey() === null) {
            throw new InvalidArgumentException('Taxonomy owners must be persisted.');
        }

        $owner = $this->owners->resolve($owner, $lock);

        $definition = $this->taxonomies->get($taxonomy);
        $ownerAlias = $this->owners->aliasFor($owner);

        if ($definition->allowedOwners !== []
            && ! in_array($owner::class, array_map($this->owners->model(...), $definition->allowedOwners), true)) {
            throw new InvalidTermOperationException(
                "Owner [{$ownerAlias}] is not allowed for taxonomy [{$taxonomy}].",
            );
        }

        return [
            $definition,
            $ownerAlias,
            TaxonomyConfiguration::modelIdentifier($owner),
            $owner,
        ];
    }

    /**
     * @return Builder
     */
    private function ownerQuery(string $ownerAlias, string $ownerId, string $taxonomy)
    {
        $query = DB::connection(TermModelRegistry::newInstance()->getConnectionName())
            ->table((new Termable)->getTable())
            ->where('termable_type', $ownerAlias)
            ->where('termable_id', $ownerId)
            ->where('taxonomy', $taxonomy);
        if (TaxonomyOwnership::declared()) {
            $query->where('owner_scope_key', $this->boundary->attributes('taxonomy.attachments')['owner_scope_key']);
        }

        return $query;
    }

    /**
     * @param  list<Term|string>  $references
     */
    private function assertBulkLimit(array $references): void
    {
        $unique = [];

        foreach ($references as $reference) {
            $unique[$reference instanceof Term ? $reference->id : trim($reference)] = true;
        }

        if (count($unique) > TaxonomyConfiguration::positiveLimit('bulk_terms', 500)) {
            throw new InvalidTermOperationException('Too many taxonomy terms were supplied.');
        }
    }
}
