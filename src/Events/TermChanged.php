<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Events;

use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Taxonomy\Enums\TermChangeOperation;
use Nvl\Taxonomy\Ownership\TaxonomyOwnership;

/**
 * Signals a committed structural or localized term change.
 *
 * @api
 */
final readonly class TermChanged implements DomainEvent, OwnershipQueuedJob
{
    /**
     * Create one committed term mutation event.
     */
    public function __construct(
        public string $termId,
        public string $taxonomy,
        public TermChangeOperation $operation,
        public int $revision,
        public int $schemaVersion = 1,
    ) {
        $this->ownershipEnvelope = TaxonomyOwnership::envelope();
    }

    private OwnershipJobEnvelope $ownershipEnvelope;

    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownershipEnvelope;
    }

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
