<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests;

/** Defers adoption so actual released legacy schemas can be reviewed and converted. */
abstract class HistoricalOwnershipTestCase extends OwnershipTestCase
{
    protected function adoptOwnership(): void {}
}
