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
 * Determines which rule proposals can write memberships under the host's authority.
 *
 * @api
 */
interface TaxonomyMembershipPolicy
{
    public function mode(Model $owner, TaxonomyDefinition $taxonomy, Authenticatable|SystemActor $actor, TaxonomyMembershipProposal $proposal): TaxonomyMembershipMode;
}
