<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Structural attached term identity with localized display copy and pivot order.
 *
 * @api
 */
#[TypeScript]
final class OwnerTaxonomyTerm extends Data
{
    /** Retain public structural fields and preloaded translated values. */
    public function __construct(
        public readonly string $id,
        public readonly string $vocabulary,
        public readonly string $slug,
        public readonly ?string $parentId,
        public readonly int $position,
        public readonly string $name,
        public readonly ?string $description,
    ) {}
}
