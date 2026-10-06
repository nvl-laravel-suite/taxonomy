<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Events;

use Nvl\Support\Contracts\DomainEvent;
use Nvl\Taxonomy\Enums\TermChangeOperation;

/**
 * Signals a committed structural or localized term change.
 *
 * @api
 */
final readonly class TermChanged implements DomainEvent
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
    ) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
