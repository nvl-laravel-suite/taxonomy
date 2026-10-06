<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Contracts;

use Nvl\Taxonomy\Enums\DeleteTermStrategy;
use Nvl\Taxonomy\Models\Term;

/**
 * Defines the supported delete term workflow.
 *
 * @api
 */
interface DeleteTermContract
{
    /**
     * Delete one term at an explicit revision and handling strategy.
     */
    public function execute(
        Term|string $term,
        int $expectedRevision,
        DeleteTermStrategy $strategy = DeleteTermStrategy::Restrict,
        ?string $reparentTo = null,
    ): bool;
}
