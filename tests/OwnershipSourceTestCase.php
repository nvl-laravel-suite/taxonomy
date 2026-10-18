<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests;

/** Separates the package's actual data source from its actor/coordinator connection. */
abstract class OwnershipSourceTestCase extends OwnershipTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $configuration = $app['config']->get('database.connections.sqlite');
        if (getenv('NVL_OWNERSHIP_TEST_DRIVER') === 'pgsql') {
            $database = getenv('NVL_OWNERSHIP_TEST_REMOTE_DATABASE');
            if (! is_string($database) || preg_match('/^nvl_own_test_[a-z0-9_]+$/D', $database) !== 1) {
                throw new \RuntimeException('A dedicated remote native source is required.');
            }
            $configuration['database'] = $database;
        }
        $app['config']->set(['database.connections.taxonomy_source' => $configuration, 'nvl-taxonomy.connection' => 'taxonomy_source']);
    }
}
