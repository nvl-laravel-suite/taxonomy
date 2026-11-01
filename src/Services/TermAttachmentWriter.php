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
use Nvl\Taxonomy\Exceptions\InvalidTermOperationException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Ownership\TaxonomyBoundary as OwnershipBoundary;
use Nvl\Taxonomy\Ownership\TaxonomyOwnership;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyDefinition;
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
    ) {}

    /**
     * Replace one owner's complete ordered vocabulary attachment set.
     *
     * @param  list<Term|string>  $references
     */
    public function sync(Model $owner, string $taxonomy, array $references, Authenticatable|SystemActor $actor): void
    {
        if (TaxonomyOwnership::declared()) {
            $canonical = $this->owners->resolve($owner, lock: true);
            $this->persistence->save($canonical, function () use ($canonical, $taxonomy, $references, $actor): bool {
                $this->syncRecords($canonical, $taxonomy, $references, $actor);

                return true;
            });

            return;
        }
        $this->syncRecords($owner, $taxonomy, $references, $actor);
    }

    /** Persist the admitted attachment set under its actual host source lease.
     * @param  list<Term|string>  $references
     */
    private function syncRecords(Model $owner, string $taxonomy, array $references, Authenticatable|SystemActor $actor): void
    {
        [$definition, $ownerAlias, $ownerId] = $this->context($owner, $taxonomy, lock: true);
        $this->assertBulkLimit($references);
        $resolved = $this->terms->resolve($taxonomy, $references, $actor);

        if ($definition->exclusive && count($resolved) > 1) {
            throw new InvalidTermOperationException(
                "Taxonomy [{$taxonomy}] allows only one term per owner.",
            );
        }

        $ids = array_map(static fn (Term $term): string => $term->id, $resolved);
        $query = $this->ownerQuery($ownerAlias, $ownerId, $taxonomy);
        $existing = [];
        foreach ((clone $query)->lockForUpdate()->get(['id', 'term_id', 'position']) as $attachment) {
            $position = filter_var($attachment->position, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if (! is_string($attachment->id) || ! is_string($attachment->term_id) || ! is_int($position)) {
                throw new InvalidTermOperationException('Stored taxonomy attachments must have canonical identifiers.');
            }
            $existing[$attachment->term_id] = ['id' => $attachment->id, 'position' => $position];
        }

        if ($ids === []) {
            $query->delete();

            return;
        }

        $removed = array_diff(array_keys($existing), $ids);
        if ($removed !== []) {
            (clone $query)->whereIn('term_id', $removed)->delete();
        }

        $now = now();
        $rows = [];

        foreach ($ids as $position => $termId) {
            if (isset($existing[$termId])) {
                if ($existing[$termId]['position'] !== $position) {
                    (clone $query)->where('id', $existing[$termId]['id'])
                        ->update(['position' => $position, 'updated_at' => $now]);
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
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::connection(TermModelRegistry::newInstance()->getConnectionName())
                ->table((new Termable)->getTable())
                ->insert($rows);
        }
    }

    /**
     * Append unique terms while preserving the existing attachment order.
     *
     * @param  list<Term|string>  $references
     */
    public function append(Model $owner, string $taxonomy, array $references, Authenticatable|SystemActor $actor): void
    {
        [, $ownerAlias, $ownerId] = $this->context($owner, $taxonomy, lock: true);
        $current = $this->ownerQuery($ownerAlias, $ownerId, $taxonomy)
            ->orderBy('position')
            ->pluck('term_id')
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->values()
            ->all();
        $incoming = array_map(
            static fn (Term|string $term): Term|string => $term,
            $references,
        );

        $this->sync($owner, $taxonomy, [...$current, ...$incoming], $actor);
    }

    /**
     * Remove selected terms, or the complete set when none are supplied.
     *
     * @param  list<Term|string>  $references
     */
    public function detach(Model $owner, string $taxonomy, array $references, Authenticatable|SystemActor $actor): int
    {
        if (! TaxonomyOwnership::declared()) {
            return $this->detachRecords($owner, $taxonomy, $references, $actor);
        }
        $canonical = $this->owners->resolve($owner, lock: true);
        $deleted = 0;
        $this->persistence->save($canonical, function () use ($canonical, $taxonomy, $references, $actor, &$deleted): bool {
            $deleted = $this->detachRecords($canonical, $taxonomy, $references, $actor);

            return true;
        });

        return $deleted;
    }

    /** Remove admitted attachments while the source lease remains active.
     * @param  list<Term|string>  $references
     */
    private function detachRecords(Model $owner, string $taxonomy, array $references, Authenticatable|SystemActor $actor): int
    {
        [, $ownerAlias, $ownerId] = $this->context($owner, $taxonomy, lock: true);
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

        $query->lockForUpdate()->get();

        if ($ids !== []) {
            $query->whereIn('term_id', $ids);
        }

        return $query->delete();
    }

    /**
     * @return array{TaxonomyDefinition, string, string}
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
