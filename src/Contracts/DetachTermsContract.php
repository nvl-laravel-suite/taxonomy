<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Taxonomy\Models\Term;

/**
 * Defines the supported detach terms workflow.
 *
 * @api
 */
interface DetachTermsContract
{
    /**
     * Remove selected terms, or every term when none are supplied.
     *
     * @param  list<Term|string>  $terms
     */
    public function execute(Model $owner, string $taxonomy, array $terms = []): int;
}
