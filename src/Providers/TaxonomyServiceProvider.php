<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Providers;

use Illuminate\Container\Container;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Taxonomy\Commands;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Taxonomy\Support\SlugGenerator;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Translatable\Services\TranslationResourceRegistry;

/**
 * Registers taxonomy configuration, registries, commands, migrations, and integration resources.
 */
final class TaxonomyServiceProvider extends ServiceProvider
{
    /**
     * Register merged configuration and package singletons.
     */
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../../config/taxonomy.php', 'taxonomy');

        $this->app->singleton(TaxonomyRegistry::class);
        $this->app->singleton(SlugGenerator::class, function (Container $app): SlugGenerator {
            $generator = config('taxonomy.slugs.generator', SlugGenerator::class);

            if (! is_string($generator) || ! is_a($generator, SlugGenerator::class, true)) {
                throw new InvalidArgumentException(
                    'The taxonomy slug generator must extend ['.SlugGenerator::class.'].',
                );
            }

            $instance = $app->build($generator);

            return $instance;
        });
        $this->app->singleton(TaxonomyOwnerRegistry::class, function (): TaxonomyOwnerRegistry {
            $registry = new TaxonomyOwnerRegistry;
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
    ): void {
        foreach ($taxonomies->all() as $definition) {
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
}
