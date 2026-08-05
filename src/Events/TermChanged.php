<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nvl\Taxonomy\Enums\TermChangeOperation;

/**
 * Signals a committed structural or localized term change.
 */
final readonly class TermChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * Create one committed term mutation event.
     */
    public function __construct(
        public string $termId,
        public string $taxonomy,
        public TermChangeOperation $operation,
        public int $revision,
    ) {}
}
