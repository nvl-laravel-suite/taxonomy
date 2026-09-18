<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Taxonomy\Models\Category;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Providers\TaxonomyServiceProvider;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Taxonomy\Tests\Fixtures\TaxonomyTenancyDirectory;
use Nvl\Taxonomy\Tests\Fixtures\TaxonomyTenancyFixtureServiceProvider;
use Nvl\Taxonomy\Tests\Fixtures\TaxonomyTenancyMaintenanceMode;
use Nvl\Taxonomy\Tests\Fixtures\TaxonomyTenancyPlatformAccess;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;

/** Boots Taxonomy's non-transactional tenancy adoption fixture. */
abstract class TaxonomyTenancyTestCase extends Orchestra
{
    use DatabaseMigrations;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            DataServiceProvider::class,
            SupportServiceProvider::class,
            TenancyServiceProvider::class,
            TaxonomyTenancyFixtureServiceProvider::class,
            TranslatableServiceProvider::class,
            TaxonomyServiceProvider::class,
        ];
    }

    /** Configure structural ownership before package providers boot. */
    protected function defineEnvironment($app): void
    {
        $defaultConnection = $app['config']->get('database.default');
        $connection = is_string($defaultConnection)
            ? $app['config']->get("database.connections.{$defaultConnection}")
            : null;
        $fixtureConnection = null;

        if (is_array($connection)) {
            $app['config']->set('database.connections.taxonomy_tenant_fixture', $connection);
            $app['config']->set('database.default', 'taxonomy_tenant_fixture');
            $fixtureConnection = 'taxonomy_tenant_fixture';
        }

        $app['config']->set([
            'cache.default' => 'array',
            'taxonomy.migrations.enabled' => true,
            'taxonomy.storage.connection' => $fixtureConnection,
            'taxonomy.table_names.terms' => 'tenant_terms',
            'taxonomy.table_names.terms_i18n' => 'tenant_term_translations',
            'taxonomy.table_names.termables' => 'tenant_termables',
            'taxonomy.table_names.term_tenant_adoption_copies' => 'tenant_term_adoption_copies',
            'taxonomy.taxonomies.tag' => ['model' => Term::class, 'hierarchical' => false, 'exclusive' => false, 'open' => true],
            'taxonomy.taxonomies.category' => ['model' => Category::class, 'hierarchical' => true, 'exclusive' => true, 'open' => false, 'max_depth' => 3],
            'taxonomy.owners' => ['posts' => Post::class],
            'tenancy.enabled' => true,
            'tenancy.connection' => $fixtureConnection,
            'tenancy.resources.taxonomy' => 'tenant',
            'tenancy.directory.driver' => 'host',
            'tenancy.directory.adapter' => null,
            'tenancy.access.platform' => null,
        ]);
        $app->instance(MaintenanceMode::class, new TaxonomyTenancyMaintenanceMode);
        $app->instance(TenantDirectory::class, new TaxonomyTenancyDirectory);
        $app->instance(PlatformAccess::class, new TaxonomyTenancyPlatformAccess);
    }

    /** Load only the opt-in core schema after normal Taxonomy migrations. */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $provider = new ReflectionClass(TenancyServiceProvider::class);
        $this->loadMigrationsFrom(dirname($provider->getFileName()).'/../../database/migrations/tenancy');
    }
}
