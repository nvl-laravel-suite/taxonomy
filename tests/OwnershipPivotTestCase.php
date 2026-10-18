<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests;

use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Tests\Fixtures\PivotOwnershipTerm;

abstract class OwnershipPivotTestCase extends OwnershipTestCase
{
    /** @return class-string<Term> */
    protected function termClass(): string
    {
        return PivotOwnershipTerm::class;
    }
}
