<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Contracts;

use Nvl\Taxonomy\Models\Term;

/**
 * Defines the supported merge terms workflow.
 *
 * @api
 */
interface MergeTermsContract
{
    /**
     * Merge a source term into a destination at explicit optimistic revisions.
     */
    public function execute(
        Term|string $source,
        Term|string $destination,
        int $expectedSourceRevision,
        int $expectedDestinationRevision,
    ): Term;
}
