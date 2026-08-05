<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Support;

use Illuminate\Database\Eloquent\Collection;
use Nvl\Taxonomy\Models\Term;

/**
 * Carries the locked terms and children validated for one merge attempt.
 */
final readonly class TermMergeContext
{
    /**
     * Create an immutable locked merge context.
     *
     * @param  Collection<int, Term>  $children
     */
    public function __construct(
        public Term $source,
        public Term $destination,
        public Collection $children,
    ) {}
}
