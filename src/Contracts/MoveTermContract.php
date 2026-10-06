<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Contracts;

use Nvl\Taxonomy\Models\Term;

/**
 * Defines the supported move term workflow.
 *
 * @api
 */
interface MoveTermContract
{
    /**
     * Move one subtree to a validated parent and sibling position.
     */
    public function execute(
        Term|string $term,
        ?string $parentId,
        int $position,
        int $expectedRevision,
    ): Term;
}
