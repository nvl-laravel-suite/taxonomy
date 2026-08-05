<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One non-mutating Taxonomy readiness check.
 */
#[TypeScript]
final class TaxonomyDoctorCheckData extends Data
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
