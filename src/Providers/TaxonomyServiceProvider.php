<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Providers;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Taxonomy\Commands;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Models\TermTranslation;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Taxonomy\Support\SlugGenerator;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Taxonomy\Tenancy\TaxonomyAdoptionAdapter;
use Nvl\Taxonomy\Tenancy\TaxonomyTenancyResources;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Translatable\Services\TranslationResourceRegistry;

/**
 * Registers taxonomy configuration, registries, commands, migrations, and integration resources.
 */
final class TaxonomyServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;

    /**
     * Register merged configuration and package singletons.
     */
    public function register(): void
    {
        $this->mergePackageConfiguration(__DIR__.'/../../config/taxonomy.php', 'taxonomy');

        $this->app->singleton(TaxonomyRegistry::class);
        $this->app->scoped(SlugGenerator::class, function (Container $app): SlugGenerator {
            $generator = config('taxonomy.slugs.generator', SlugGenerator::class);

            if (! is_string($generator) || ! is_a($generator, SlugGenerator::class, true)) {
                throw new InvalidArgumentException(
                    'The taxonomy slug generator must extend ['.SlugGenerator::class.'].',
                );
            }

            $instance = $app->build($generator);

            return $instance;
        });
        $this->app->singleton(TaxonomyOwnerRegistry::class, function (Container $app): TaxonomyOwnerRegistry {
            $registry = new TaxonomyOwnerRegistry(
                $app->make(TenantResourceRegistry::class),
                $app->make(TenantBoundary::class),
            );
            $configuredOwners = config('taxonomy.owners', []);

            if (! is_array($configuredOwners)) {
                throw new InvalidArgumentException('Taxonomy owners must be an alias-to-model array.');
            }

            foreach ($configuredOwners as $alias => $model) {
                if (! is_string($alias) || ! is_string($model)) {
                    throw new InvalidArgumentException(
                        'Taxonomy owners must use string aliases and model class names.',
                    );
                }

                $registry->register($alias, $model);
            }

            return $registry;
        });
    }

    /**
     * Boot validated registries, resources, migrations, and commands.
     */
    public function boot(
        TranslationResourceRegistry $translationResources,
        TypeScriptSourceRegistry $typeScriptSources,
        TaxonomyOwnerRegistry $owners,
        TaxonomyRegistry $taxonomies,
        TaxonomyTenancyResources $tenancyResources,
        TenantResourceRegistry $tenantResources,
        TenantAdoptionRegistry $tenantAdoptions,
        TenantBoundary $tenantBoundary,
    ): void {
        $tenancyResources->register($tenantResources);
        $tenantAdoptions->register('taxonomy', TaxonomyAdoptionAdapter::class);
        $this->registerTenantScopes($tenantBoundary);
        foreach ($taxonomies->all() as $definition) {
            if (config('tenancy.enabled') === true && $definition->model !== Term::class) {
                throw new TenantConfigurationInvalid(
                    'Tenant-enabled Taxonomy vocabularies must use the canonical Term model.',
                );
            }
            $unknownOwners = array_diff(
                $definition->allowedOwners,
                array_keys($owners->all()),
            );

            if ($unknownOwners !== []) {
                throw new InvalidArgumentException(
                    "Taxonomy [{$definition->taxonomy}] references unknown owner aliases: "
                    .implode(', ', $unknownOwners).'.',
                );
            }
        }

        $typeScriptSources->register(__DIR__.'/..', 'nvl/taxonomy');

        $this->publishes([
            __DIR__.'/../../config/taxonomy.php' => config_path('taxonomy.php'),
        ], 'taxonomy-config');

        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'taxonomy-migrations');
        if ((bool) config('taxonomy.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'taxonomy-skills');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\RebuildTreeCommand::class,
                Commands\PruneOrphansCommand::class,
                Commands\MergeTermsCommand::class,
                Commands\TaxonomyDoctorCommand::class,
            ]);
        }

        $translationResources->register(
            key: 'taxonomy.terms',
            modelClass: Term::class,
            label: 'Taxonomy terms',
            searchableColumns: ['taxonomy', 'slug'],
            displayColumns: ['taxonomy', 'slug'],
            orderColumn: 'position',
        );
    }

    /** Register tenant predicates on every configured term model and inherited row. */
    private function registerTenantScopes(TenantBoundary $boundary): void
    {
        Term::addGlobalScope('tenant', static function (Builder $query) use ($boundary): void {
            $boundary->query($query, 'taxonomy.terms');
        });
        Termable::addGlobalScope('tenant', static function (Builder $query) use ($boundary): void {
            $boundary->query($query, 'taxonomy.attachments');
        });
        TermTranslation::addGlobalScope('tenant', static function (Builder $query) use ($boundary): void {
            $boundary->query($query, 'taxonomy.translations');
        });
    }
}
