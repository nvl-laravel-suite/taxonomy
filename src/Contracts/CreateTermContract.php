<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Contracts;

use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Taxonomy\Models\Term;

/**
 * Defines the supported create term workflow.
 *
 * @api
 */
interface CreateTermContract
{
    /**
     * Persist one validated term and dispatch its committed change event.
     */
    public function execute(MutateTermPayload $data): Term;
}
