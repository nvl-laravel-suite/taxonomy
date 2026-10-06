<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Taxonomy\Contracts\CreateTermContract;
use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Taxonomy\Enums\TermChangeOperation;
use Nvl\Taxonomy\Events\TermChanged;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TermWriter;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/**
 * Creates one taxonomy term and all supplied locale rows atomically.
 *
 * @api
 */
final readonly class CreateTermAction implements CreateTermContract
{
    /**
     * Create the term action.
     */
    public function __construct(
        private TermWriter $writer,
        private TaxonomyRegistry $taxonomies,
        private DomainEventDispatcher $domainEvents,
    ) {}

    /**
     * Persist one validated term and dispatch its committed change event.
     */
    public function execute(MutateTermPayload $data): Term
    {
        $definition = $this->taxonomies->get($data->taxonomy);
        $connection = (new ($definition->model))->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($data): Term {
            $term = $this->writer->create($data);
            $this->domainEvents->dispatch(new TermChanged(
                $term->id,
                $term->taxonomy,
                TermChangeOperation::Created,
                $term->revision,
            ), $term->getConnection());

            return $term;
        }, TaxonomyConfiguration::transactionAttempts());
    }
}
