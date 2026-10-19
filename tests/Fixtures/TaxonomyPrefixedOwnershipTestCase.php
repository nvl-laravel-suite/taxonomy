<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Nvl\Taxonomy\Tests\OwnershipTestCase;

/** Exercises repeated preparation on one physically prefixed source. */
class TaxonomyPrefixedOwnershipTestCase extends OwnershipTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.connections.sqlite.prefix', 'review_');
    }
}
