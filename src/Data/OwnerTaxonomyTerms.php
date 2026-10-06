<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Data;

use Nvl\Support\Owners\OwnerResultMap;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use stdClass;

/**
 * Exact native owner objects and stable first-request identity order.
 *
 * @api
 */
#[TypeScript]
final class OwnerTaxonomyTerms extends Data
{
    /**
     * Retain explicit per-owner vocabulary objects.
     *
     * @param  list<array{type: string, id: string}>  $order
     */
    public function __construct(
        #[LiteralTypeScriptType('Record<string, Record<string, OwnerTaxonomyTermList>>')]
        public readonly stdClass $owners,
        #[LiteralTypeScriptType('Array<{ type: string; id: string }>')]
        public readonly array $order,
    ) {}

    /**
     * Serialize complete admitted identities through the shared object map.
     *
     * @param  OwnerResultMap<OwnerTaxonomyTermList>  $map
     */
    public static function fromMap(OwnerResultMap $map): self
    {
        return new self($map->jsonSerialize(), $map->order());
    }
}
