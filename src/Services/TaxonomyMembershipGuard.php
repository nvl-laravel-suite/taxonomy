<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Contracts\TaxonomyMembershipPolicy;
use Nvl\Taxonomy\Enums\TaxonomyMembershipMode;
use Nvl\Taxonomy\Enums\TaxonomyMembershipOperation;
use Nvl\Taxonomy\Exceptions\InvalidTermOperationException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Support\TaxonomyDefinition;
use Nvl\Taxonomy\Support\TaxonomyMembershipProposal;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/**
 * Reviews every membership write with the host policy inside the caller's owner lock and transaction.
 *
 * @internal
 */
final readonly class TaxonomyMembershipGuard
{
    /** Source identity reported to the policy for manual and structural writes. */
    public const string ManualSource = 'manual';

    public function __construct(
        private TaxonomyRegistry $taxonomies,
        private TaxonomyOwnerRegistry $owners,
    ) {}

    /**
     * Review one owner's complete resulting set. Manual operations must be applied; only reconciliation may shadow.
     *
     * Without a bound policy, manual operations apply as before and reconciliation fails to resolve its policy.
     */
    public function review(
        Model $owner, TaxonomyDefinition $taxonomy,
        Authenticatable|SystemActor $actor, TaxonomyMembershipProposal $proposal,
    ): TaxonomyMembershipMode {
        $manual = $proposal->operation->isManual();
        if ($manual && ! app()->bound(TaxonomyMembershipPolicy::class)) {
            return TaxonomyMembershipMode::Apply;
        }
        $mode = app(TaxonomyMembershipPolicy::class)->mode($owner, $taxonomy, $actor, $proposal);
        if ($manual && $mode !== TaxonomyMembershipMode::Apply) {
            throw new InvalidTermOperationException(
                "The membership policy did not authorize the {$proposal->operation->value} of taxonomy [{$taxonomy->taxonomy}] memberships.",
            );
        }

        return $mode;
    }

    /**
     * Review a manual write of one owner's complete resulting set.
     *
     * @param  list<Term|string>  $terms
     */
    public function reviewManual(Model $owner, string $taxonomy, Authenticatable|SystemActor $actor, array $terms, TaxonomyMembershipOperation $operation): void
    {
        $this->review($owner, $this->taxonomies->get($taxonomy), $actor, new TaxonomyMembershipProposal(self::ManualSource, $terms, $operation));
    }

    /**
     * Lock and review every owner a structural write changes, in chunks ordered by morph type then id.
     *
     * Trashed owners are reviewed; owners whose rows are gone are skipped, as nothing is left to authorize.
     * Each owner's current terms are read in the owner batch read order: position, then term id.
     * One denial throws and rolls back the caller's transaction, rejecting the whole operation.
     *
     * @param  Builder  $attachments  Attachment rows the structural write changes.
     * @param  Closure(list<string>): list<string>  $resulting  Maps an owner's current term ids to its resulting ids.
     */
    public function reviewAttachedOwners(string $taxonomy, Builder $attachments, Authenticatable|SystemActor $actor, TaxonomyMembershipOperation $operation, Closure $resulting): void
    {
        if (! app()->bound(TaxonomyMembershipPolicy::class)) {
            return;
        }
        $models = $this->owners->all();
        (clone $attachments)->select(['termable_type', 'termable_id'])->distinct()
            ->orderBy('termable_type')->orderBy('termable_id')
            ->chunk(250, function ($owners) use ($models, $taxonomy, $actor, $operation, $resulting): void {
                foreach ($owners as $attachment) {
                    $type = $attachment->termable_type;
                    $identifier = $attachment->termable_id;
                    $modelClass = is_string($type) ? ($models[$type] ?? null) : null;
                    if (! is_string($modelClass) || (! is_string($identifier) && ! is_int($identifier))) {
                        throw new InvalidTermOperationException('A taxonomy attachment has an unknown canonical owner.');
                    }
                    $owner = (new $modelClass)->newQueryWithoutScopes()->whereKey($identifier)->first();
                    if (! $owner instanceof Model) {
                        continue;
                    }
                    $canonical = $this->owners->resolveForDeletion($owner);
                    $current = $canonical->getConnection()->table((new Termable)->getTable())
                        ->where('termable_type', $type)->where('termable_id', (string) $identifier)->where('taxonomy', $taxonomy)
                        ->orderBy('position')->orderBy('term_id')->pluck('term_id')
                        ->filter(static fn (mixed $id): bool => is_string($id))->all();
                    $this->reviewManual($canonical, $taxonomy, $actor, $resulting(array_values($current)), $operation);
                }
            });
    }

    /** Relationship helpers bypass review, so they are refused while a membership policy is bound. */
    public static function assertRelationWritesAllowed(): void
    {
        if (app()->bound(TaxonomyMembershipPolicy::class)) {
            throw new InvalidTermOperationException('Taxonomy relationship writes bypass the membership policy. Use TaxonomyAssignments.');
        }
    }
}
