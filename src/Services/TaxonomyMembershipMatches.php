<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nvl\Taxonomy\Enums\TaxonomyMembershipMode;
use Nvl\Taxonomy\Models\MembershipMatch;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Ownership\TaxonomyBoundary;
use Nvl\Taxonomy\Ownership\TaxonomyOwnership;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyMembershipProposal;

/**
 * Stores the latest reviewed proposal per owner, taxonomy and source inside the caller's owner lock and transaction.
 *
 * @internal
 */
final readonly class TaxonomyMembershipMatches
{
    public function __construct(
        private TaxonomyBoundary $boundary,
        private TaxonomyOwnerRegistry $owners,
    ) {}

    /** Upsert the match without resolving or creating any proposed term. */
    public function store(Model $owner, string $taxonomy, TaxonomyMembershipProposal $proposal, TaxonomyMembershipMode $mode): void
    {
        $model = new MembershipMatch;
        $scope = TaxonomyOwnership::declared() ? $this->boundary->attributes('taxonomy.membership_matches') : [];
        $identity = [
            ...$scope,
            'owner_type' => $this->owners->aliasFor($owner),
            'owner_id' => TaxonomyConfiguration::modelIdentifier($owner),
            'taxonomy' => $taxonomy,
            'source' => $proposal->source,
        ];
        $values = [
            'proposal' => json_encode(array_map(
                static fn (Term|string $term): string => $term instanceof Term ? $term->id : $term,
                $proposal->terms,
            ), JSON_THROW_ON_ERROR),
            'mode' => $mode->value,
            'matched_at' => now(),
        ];
        $table = $model->getConnection()->table($model->getTable());
        $existing = (clone $table)->where($identity)->lockForUpdate()->value('id');

        if (is_string($existing)) {
            (clone $table)->where('id', $existing)->update($values);

            return;
        }
        $table->insert(['id' => (string) Str::uuid(), ...$identity, ...$values]);
    }
}
