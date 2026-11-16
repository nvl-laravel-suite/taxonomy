<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipModelPersistence;
use Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization;
use Nvl\Taxonomy\Contracts\TaxonomyCatalogAuthorization;
use Nvl\Taxonomy\Enums\TaxonomyMembershipOperation;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Ownership\TaxonomyOwnership;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

/**
 * Reads and changes the order of attachments within a canonical term.
 *
 * @api
 */
final readonly class TaxonomyMemberOrder
{
    public function __construct(
        private TaxonomyBatchQueryBuilder $queries,
        private TaxonomyCatalogAuthorization $catalog,
        private TaxonomyBatchAuthorization $policy,
        private TaxonomyActorPolicy $actors,
        private OwnershipModelPersistence $persistence,
        private TaxonomyMembershipGuard $guard,
    ) {}

    /**
     * Read attachment identities under the dedicated term-level membership grant.
     *
     * @return Paginator<int, Termable>
     */
    public function read(string $taxonomy, string $termId, Authenticatable|SystemActor $actor, int $perPage = 100, int $page = 1): Paginator
    {
        if ($perPage < 1 || $perPage > 100 || $page < 1 || $page > intdiv(PHP_INT_MAX, $perPage)) {
            throw new InvalidArgumentException('Invalid Taxonomy membership page.');
        }

        [$term] = $this->admit($taxonomy, $termId, $actor, 'read-members');

        return $this->members($term)->orderByRaw('member_position IS NULL')
            ->orderBy('member_position')->orderBy('id')
            ->simplePaginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Set explicit member positions by attachment UUID. Null clears a stored position.
     *
     * @param  array<array-key, mixed>  $positions  Map attachment UUIDs to nonnegative integers or null.
     */
    public function reorder(string $taxonomy, string $termId, array $positions, Authenticatable|SystemActor $actor): void
    {
        if (count($positions) > TaxonomyConfiguration::positiveLimit('bulk_terms', 500)) {
            throw new InvalidArgumentException('Taxonomy member order exceeds the configured bulk term limit.');
        }
        $validated = [];
        foreach ($positions as $id => $position) {
            if (! is_string($id) || ! Str::isUuid($id) || ($position !== null && (! is_int($position) || $position < 0 || $position > 2147483647))) {
                throw new InvalidArgumentException('Taxonomy member order requires attachment UUIDs and nonnegative integer positions or null.');
            }
            $validated[$id] = $position;
        }
        $connection = TermModelRegistry::newInstance()->getConnection();
        $connection->transaction(function () use ($taxonomy, $termId, $validated, $actor): void {
            [$term, $actor] = $this->admit($taxonomy, $termId, $actor, 'reorder-members', lock: true);
            $persist = function () use ($term, $validated, $actor): bool {
                $this->writePositions($term, $validated, $actor);

                return true;
            };
            if (TaxonomyOwnership::declared()) {
                $this->persistence->save($term, $persist);
            } else {
                $persist();
            }
        }, TaxonomyConfiguration::transactionAttempts());
    }

    /** @param array<string, int|null> $positions */
    private function writePositions(Term $term, array $positions, Authenticatable|SystemActor $actor): void
    {
        $members = $this->members($term);
        $selected = (clone $members)->whereKey(array_keys($positions))->orderBy('id')->lockForUpdate()->get(['id', 'member_position']);
        if ($selected->count() !== count($positions)) {
            throw new InvalidArgumentException('Each reordered attachment must belong to the selected term.');
        }
        $this->guard->reviewAttachedOwners(
            $term->taxonomy,
            (clone $members)->whereKey(array_keys($positions))->toBase(),
            $actor,
            TaxonomyMembershipOperation::Reorder,
            static fn (array $current): array => $current,
        );
        $now = now();
        foreach ($selected as $member) {
            if ($member->member_position !== $positions[$member->id]) {
                (clone $members)->whereKey($member->id)->update(['member_position' => $positions[$member->id], 'updated_at' => $now]);
            }
        }
    }

    /**
     * Authorize the canonical actor and load the term, returning both so writes review the fresh actor.
     *
     * @return array{0: Term, 1: Authenticatable|SystemActor}
     */
    private function admit(string $taxonomy, string $termId, Authenticatable|SystemActor $actor, string $ability, bool $lock = false): array
    {
        if (! Str::isUuid($termId)) {
            throw new InvalidArgumentException('Taxonomy term identities must be UUIDs.');
        }
        $actor = $this->actors->authorize($actor, 'read');
        $this->catalog->authorizeVocabulary($actor, $taxonomy);
        $query = $this->queries->terms($taxonomy, $this->policy, $actor)->whereKey($termId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $term = $query->firstOrFail();
        $this->actors->authorize($actor, $ability, $term, $taxonomy);

        return [$term, $actor];
    }

    /** @return Builder<Termable> */
    private function members(Term $term): Builder
    {
        return $this->queries->attachments()->where('taxonomy', $term->taxonomy)->where('term_id', $term->id);
    }
}
