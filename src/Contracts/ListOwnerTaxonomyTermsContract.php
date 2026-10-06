<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Taxonomy\Data\OwnerTaxonomyTerms;

/**
 * Bounded authorized DTO reads for explicitly requested native owner identities.
 *
 * @api
 */
interface ListOwnerTaxonomyTermsContract
{
    /**
     * Return requested vocabulary lists for every admitted persisted owner.
     *
     * @param  list<Model>  $owners
     * @param  list<string>  $vocabularies
     */
    public function execute(array $owners, array $vocabularies, ?string $locale = null): OwnerTaxonomyTerms;
}
