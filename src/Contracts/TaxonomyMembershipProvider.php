<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Enums\TaxonomyMembershipMode;
use Nvl\Taxonomy\Support\TaxonomyDefinition;
use Nvl\Taxonomy\Support\TaxonomyMembershipProposal;

/**
 * Supplies application-managed rules and records their result within the owner transaction.
 *
 * @api
 */
interface TaxonomyMembershipProvider
{
    public function propose(Model $owner, TaxonomyDefinition $taxonomy, Authenticatable|SystemActor $actor): TaxonomyMembershipProposal;

    /** Use the owner's connection for durable observations and tolerate transaction retries. */
    public function record(Model $owner, TaxonomyDefinition $taxonomy, Authenticatable|SystemActor $actor, TaxonomyMembershipProposal $proposal, TaxonomyMembershipMode $mode): void;
}
