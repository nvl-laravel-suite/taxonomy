<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests;

use Illuminate\Foundation\Application;

/** Exercises the optional-runtime boundary without loading Tenancy's provider. */
abstract class StandaloneTestCase extends TestCase
{
    /**
     * Keep only required package providers and Core's inert tenancy services.
     *
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_values(array_filter(
            parent::getPackageProviders($app),
            static fn (string $provider): bool => $provider !== 'Nvl\\Tenancy\\Providers\\TenancyServiceProvider',
        ));
    }
}
