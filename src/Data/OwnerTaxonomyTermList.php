<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use stdClass;

/**
 * Requested vocabulary names mapped to ordered DTO lists, including empties.
 *
 * @api
 */
#[TypeScript]
final class OwnerTaxonomyTermList extends Data
{
    /** Retain each declared vocabulary as an object-valued map entry. */
    public function __construct(
        #[LiteralTypeScriptType('Record<string, Array<OwnerTaxonomyTerm>>')]
        public readonly stdClass $vocabularies,
    ) {}
}
