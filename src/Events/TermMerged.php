<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Events;

use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Taxonomy\Ownership\TaxonomyOwnership;

/**
 * Captures both term identities for host integrations after a committed merge.
 *
 * @api
 */
final readonly class TermMerged implements DomainEvent, OwnershipQueuedJob
{
    private OwnershipJobEnvelope $ownershipEnvelope;

    public function __construct(
        public string $sourceId,
        public string $destinationId,
        public string $taxonomy,
        public int $destinationRevision,
    ) {
        $this->ownershipEnvelope = TaxonomyOwnership::envelope();
    }

    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownershipEnvelope;
    }

    public function schemaVersion(): int
    {
        return 1;
    }
}
