<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyDefinition;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

/**
 * Maintains one owner's ordered taxonomy attachment set inside caller transactions.
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
    ) {}

    /**
     * Replace one owner's complete ordered vocabulary attachment set.
     *
     * @param  list<Term|string>  $references
     */
    public function sync(Model $owner, string $taxonomy, array $references): void
    {
        [$definition, $ownerAlias, $ownerId, $tenant] = $this->context($owner, $taxonomy, lock: true);
        $this->assertBulkLimit($references);
        $resolved = $this->terms->resolve($taxonomy, $references);

        if ($definition->exclusive && count($resolved) > 1) {
            throw new InvalidArgumentException(
                "Taxonomy [{$taxonomy}] allows only one term per owner.",
            );
        }

        $ids = array_map(static fn (Term $term): string => $term->id, $resolved);
        $query = $this->ownerQuery($ownerAlias, $ownerId, $taxonomy, $tenant);
        $query->lockForUpdate()->get();
        $query->delete();

        if ($ids === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($ids as $position => $termId) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                ...($tenant === null ? [] : ['tenant_id' => $tenant]),
                'term_id' => $termId,
                'termable_type' => $ownerAlias,
                'termable_id' => $ownerId,
                'taxonomy' => $taxonomy,
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::connection((new Term)->getConnectionName())
            ->table((new Termable)->getTable())
            ->insert($rows);
    }

    /**
     * Append unique terms while preserving the existing attachment order.
     *
     * @param  list<Term|string>  $references
     */
    public function append(Model $owner, string $taxonomy, array $references): void
    {
        [, $ownerAlias, $ownerId, $tenant] = $this->context($owner, $taxonomy, lock: true);
        $current = $this->ownerQuery($ownerAlias, $ownerId, $taxonomy, $tenant)
            ->orderBy('position')
            ->pluck('term_id')
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->values()
            ->all();
        $incoming = array_map(
            static fn (Term|string $term): Term|string => $term,
            $references,
        );

        $this->sync($owner, $taxonomy, [...$current, ...$incoming]);
    }

    /**
     * Remove selected terms, or the complete set when none are supplied.
     *
     * @param  list<Term|string>  $references
     */
    public function detach(Model $owner, string $taxonomy, array $references = []): int
    {
        [, $ownerAlias, $ownerId, $tenant] = $this->context($owner, $taxonomy, lock: true);
        $query = $this->ownerQuery($ownerAlias, $ownerId, $taxonomy, $tenant);
        $ids = [];

        if ($references !== []) {
            $this->assertBulkLimit($references);
            $ids = array_map(
                static fn (Term $term): string => $term->id,
                $this->terms->resolve($taxonomy, $references, createMissing: false),
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
     * @return array{TaxonomyDefinition, string, string, string|null}
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
            && ! in_array($ownerAlias, $definition->allowedOwners, true)) {
            throw new InvalidArgumentException(
                "Owner [{$ownerAlias}] is not allowed for taxonomy [{$taxonomy}].",
            );
        }

        $tenant = $owner->getRawOriginal('tenant_id');
        if (config('tenancy.enabled') === true && ! is_string($tenant)) {
            throw new TenantBoundaryViolation('The canonical taxonomy owner lacks tenant ownership.');
        }

        return [
            $definition,
            $ownerAlias,
            TaxonomyConfiguration::modelIdentifier($owner),
            is_string($tenant) ? $tenant : null,
        ];
    }

    /**
     * @return Builder
     */
    private function ownerQuery(string $ownerAlias, string $ownerId, string $taxonomy, ?string $tenant)
    {
        $query = DB::connection((new Term)->getConnectionName())
            ->table((new Termable)->getTable())
            ->where('termable_type', $ownerAlias)
            ->where('termable_id', $ownerId)
            ->where('taxonomy', $taxonomy);
        if ($tenant !== null) {
            $query->where('tenant_id', $tenant);
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
            throw new InvalidArgumentException('Too many taxonomy terms were supplied.');
        }
    }
}
