<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Taxonomy\Enums\TermChangeOperation;
use Nvl\Taxonomy\Events\TermChanged;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TermWriter;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Translatable\Enums\TranslationSyncMode;

/**
 * Updates one taxonomy term and its locale rows atomically.
 */
final readonly class UpdateTermAction
{
    /**
     * Create the term update action.
     */
    public function __construct(
        private TermWriter $writer,
        private TaxonomyRegistry $taxonomies,
    ) {}

    /**
     * Update a term through an explicit patch or replace translation contract.
     */
    public function execute(
        Term|string $term,
        MutateTermPayload $data,
        TranslationSyncMode $mode = TranslationSyncMode::Patch,
    ): Term {
        $termId = $term instanceof Term ? $term->id : $term;
        $definition = $this->taxonomies->get($data->taxonomy);
        $modelClass = $definition->model;
        $connection = (new $modelClass)->getConnectionName();

        return DB::connection($connection)->transaction(function () use (
            $termId,
            $modelClass,
            $data,
            $mode,
        ): Term {
            $resolvedTerm = $modelClass::query()
                ->lockForUpdate()
                ->findOrFail($termId);

            $updated = $this->writer->update($resolvedTerm, $data, $mode);
            TermChanged::dispatch(
                $updated->id,
                $updated->taxonomy,
                TermChangeOperation::Updated,
                $updated->revision,
            );

            return $updated;
        }, TaxonomyConfiguration::transactionAttempts());
    }
}
