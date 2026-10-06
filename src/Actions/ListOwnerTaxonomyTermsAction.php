<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Actions;

use Illuminate\Database\Eloquent\Model;
use Nvl\Taxonomy\Contracts\ListOwnerTaxonomyTermsContract;
use Nvl\Taxonomy\Data\OwnerTaxonomyTerms;
use Nvl\Taxonomy\Services\OwnerTaxonomyBatchReader;

/** Reads many canonical owners through the package's bounded DTO boundary. */
final readonly class ListOwnerTaxonomyTermsAction implements ListOwnerTaxonomyTermsContract
{
    /** Retain the authorized batch reader. */
    public function __construct(private OwnerTaxonomyBatchReader $reader) {}

    /**
     * Read requested vocabulary DTOs without exposing Eloquent relationships.
     *
     * @param  list<Model>  $owners
     * @param  list<string>  $vocabularies
     */
    public function execute(array $owners, array $vocabularies, ?string $locale = null): OwnerTaxonomyTerms
    {
        return $this->reader->read($owners, $vocabularies, $locale);
    }
}
