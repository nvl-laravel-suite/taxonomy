<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Support;

/**
 * One non-mutating Taxonomy readiness check.
 */
final readonly class TaxonomyDoctorCheck
{
    /**
     * Create one immutable diagnostic result.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $severity,
        public readonly bool $passed,
        public readonly string $message,
    ) {}
}
