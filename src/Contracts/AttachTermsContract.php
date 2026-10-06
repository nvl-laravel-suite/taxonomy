<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Taxonomy\Models\Term;

/**
 * Defines the supported attach terms workflow.
 *
 * @api
 */
interface AttachTermsContract
{
    /**
     * Append unique terms to one persisted owner's ordered vocabulary set.
     *
     * @param  list<Term|string>  $terms
     */
    public function execute(Model $owner, string $taxonomy, array $terms): void;
}
