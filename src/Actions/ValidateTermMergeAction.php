<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TermMergeValidator;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

/**
 * Validates a term merge against locked revisions without mutation.
 */
final readonly class ValidateTermMergeAction
{
    /**
     * Create the merge validation action.
     */
    public function __construct(private TermMergeValidator $validator) {}

    /**
     * Validate a prospective merge at explicit optimistic revisions.
     */
    public function execute(
        Term|string $source,
        Term|string $destination,
        int $expectedSourceRevision,
        int $expectedDestinationRevision,
    ): void {
        $sourceId = $source instanceof Term
            ? $source->getRawOriginal($source->getKeyName())
            : $source;
        $destinationId = $destination instanceof Term
            ? $destination->getRawOriginal($destination->getKeyName())
            : $destination;

        if (! is_string($sourceId) || ! is_string($destinationId)) {
            throw new InvalidArgumentException('Canonical merge term identifiers are required.');
        }
        $connection = $this->connectionFor();

        DB::connection($connection)->transaction(
            fn () => $this->validator->validate(
                $sourceId,
                $destinationId,
                $expectedSourceRevision,
                $expectedDestinationRevision,
            ),
            TaxonomyConfiguration::transactionAttempts(),
        );
    }

    private function connectionFor(): ?string
    {
        return (new Term)->getConnectionName();
    }
}
