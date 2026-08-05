<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TermResolver;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/**
 * Resolves term models and stable slugs, creating localized open-vocabulary terms when allowed.
 */
final readonly class ResolveTermsAction
{
    /**
     * Create the term resolution action.
     */
    public function __construct(
        private TaxonomyRegistry $taxonomies,
        private TermResolver $terms,
    ) {}

    /**
     * Resolve ordered term references and create eligible open-vocabulary roots.
     *
     * @param  list<Term|string>  $terms
     * @return list<Term>
     */
    public function execute(string $taxonomy, array $terms): array
    {
        $definition = $this->taxonomies->get($taxonomy);
        $connection = (new ($definition->model))->getConnectionName();

        return DB::connection($connection)->transaction(
            fn (): array => $this->terms->resolve($taxonomy, $terms),
            TaxonomyConfiguration::transactionAttempts(),
        );
    }
}
