<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Contracts;

use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Taxonomy\Models\Term;
use Nvl\Translatable\Enums\TranslationSyncMode;

/**
 * Defines the supported update term workflow.
 *
 * @api
 */
interface UpdateTermContract
{
    /**
     * Update a term through an explicit patch or replace translation contract.
     */
    public function execute(
        Term|string $term,
        MutateTermPayload $data,
        TranslationSyncMode $mode = TranslationSyncMode::Patch,
    ): Term;
}
