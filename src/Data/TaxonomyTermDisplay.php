<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Structural vocabulary identity, resolved display copy and metadata without native model handles.
 *
 * @api
 */
#[TypeScript]
final class TaxonomyTermDisplay extends Data
{
    /**
     * Retain a localized catalogue snapshot and its ordered child projections.
     *
     * @param  array<string, mixed>|null  $meta
     * @param  list<TaxonomyTermDisplay>  $children
     */
    public function __construct(
        public readonly string $id,
        public readonly string $vocabulary,
        public readonly string $slug,
        public readonly ?string $parentId,
        public readonly int $position,
        public readonly int $revision,
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?array $meta,
        public readonly array $children = [],
    ) {}
}
