<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Providers;

use Illuminate\Container\Container;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipIntegratedProvider;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;
use Nvl\Taxonomy\Actions\AttachTermsAction;
use Nvl\Taxonomy\Actions\CreateTermAction;
use Nvl\Taxonomy\Actions\DeleteTermAction;
use Nvl\Taxonomy\Actions\DetachTermsAction;
use Nvl\Taxonomy\Actions\ListOwnerTaxonomyTermsAction;
use Nvl\Taxonomy\Actions\MergeTermsAction;
use Nvl\Taxonomy\Actions\MoveTermAction;
use Nvl\Taxonomy\Actions\SyncTermAttachmentsAction;
use Nvl\Taxonomy\Actions\UpdateTermAction;
use Nvl\Taxonomy\Commands;
use Nvl\Taxonomy\Compatibility\LegacyTaxonomyIntegration;
use Nvl\Taxonomy\Contracts\AttachTermsContract;
use Nvl\Taxonomy\Contracts\CreateTermContract;
use Nvl\Taxonomy\Contracts\DeleteTermContract;
use Nvl\Taxonomy\Contracts\DetachTermsContract;
use Nvl\Taxonomy\Contracts\ListOwnerTaxonomyTermsContract;
use Nvl\Taxonomy\Contracts\MergeTermsContract;
use Nvl\Taxonomy\Contracts\MoveTermContract;
use Nvl\Taxonomy\Contracts\SyncTermAttachmentsContract;
use Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization;
use Nvl\Taxonomy\Contracts\TaxonomyCatalogAuthorization;
use Nvl\Taxonomy\Contracts\TaxonomyCatalogReader;
use Nvl\Taxonomy\Contracts\TaxonomyTreeContract;
use Nvl\Taxonomy\Contracts\TermResolverContract;
use Nvl\Taxonomy\Contracts\UpdateTermContract;
use Nvl\Taxonomy\Ownership\TaxonomyAdoptionAdapter as OwnershipTaxonomyAdoptionAdapter;
use Nvl\Taxonomy\Ownership\TaxonomyBoundary as OwnershipBoundary;
use Nvl\Taxonomy\Ownership\TaxonomyResourceRegistrar;
use Nvl\Taxonomy\Services\RegisteredTaxonomyBatchAuthorization;
use Nvl\Taxonomy\Services\RegisteredTaxonomyCatalogAuthorization;
use Nvl\Taxonomy\Services\TaxonomyCatalog;
use Nvl\Taxonomy\Services\TaxonomyDoctor;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Taxonomy\Services\TaxonomyTree;
use Nvl\Taxonomy\Services\TermModelRegistry;
use Nvl\Taxonomy\Services\TermResolver;
use Nvl\Taxonomy\Support\SlugGenerator;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Translatable\Services\TranslationResourceRegistry;

/**
 * Registers taxonomy configuration, registries, commands, migrations, and integration resources.
 */
final class TaxonomyServiceProvider extends ServiceProvider implements OwnershipIntegratedProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    /**
     * Register merged configuration and package singletons.
     */
    public function register(): void
    {
        $this->app->bindIf(AttachTermsContract::class, AttachTermsAction::class);
        $this->app->bindIf(CreateTermContract::class, CreateTermAction::class);
        $this->app->bindIf(DeleteTermContract::class, DeleteTermAction::class);
        $this->app->bindIf(DetachTermsContract::class, DetachTermsAction::class);
        $this->app->bindIf(MergeTermsContract::class, MergeTermsAction::class);
        $this->app->bindIf(MoveTermContract::class, MoveTermAction::class);
        $this->app->bindIf(SyncTermAttachmentsContract::class, SyncTermAttachmentsAction::class);
        $this->app->bindIf(UpdateTermContract::class, UpdateTermAction::class);
        $this->app->bindIf(TaxonomyTreeContract::class, TaxonomyTree::class);
        $this->app->bindIf(TaxonomyCatalogReader::class, TaxonomyCatalog::class);
        $this->app->bindIf(TaxonomyCatalogAuthorization::class, RegisteredTaxonomyCatalogAuthorization::class);
        $this->app->bindIf(TermResolverContract::class, TermResolver::class);

        $this->app->register(SupportServiceProvider::class);
        PackageDoctorContributor::register($this->app, 'nvl/taxonomy', fn (): array => $this->app->make(TaxonomyDoctor::class)->inspect());

        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-taxonomy.php', 'taxonomy');

        $this->app->singleton(TermModelRegistry::class);
        $this->app->booting(fn () => $this->app->make(TermModelRegistry::class)->prepare());
        $this->app->singleton(TaxonomyRegistry::class);
        $this->app->bindIf(ListOwnerTaxonomyTermsContract::class, ListOwnerTaxonomyTermsAction::class);
        $this->app->bindIf(TaxonomyBatchAuthorization::class, RegisteredTaxonomyBatchAuthorization::class);
        $this->app->scoped(SlugGenerator::class, function (Container $app): SlugGenerator {
            $generator = config('nvl-taxonomy.slugs.generator', SlugGenerator::class);

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
                $app->make(OwnershipBoundary::class),
                $app->make(OwnerRegistry::class),
            );
            $configuredOwners = config('nvl-taxonomy.owners', []);

            if (! is_array($configuredOwners)) {
                throw new InvalidArgumentException('Taxonomy owners must be an alias-to-model array.');
            }

            foreach ($configuredOwners as $alias => $model) {
                if (is_int($alias) && is_string($model)) {
                    $alias = $model;
                }
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
        $this->app->make(GlobalNames::class)->translations('taxonomy', __DIR__.'/../../lang', $this->app->make('translation.loader'));
        $this->publishes([
            __DIR__.'/../../lang' => lang_path('vendor/nvl-taxonomy'),
        ], 'nvl-taxonomy-translations');
        $this->app->make(LegacyTaxonomyIntegration::class)->registerResources();
        $this->app->make(TaxonomyResourceRegistrar::class)->register($this->app->make(OwnershipResourceRegistry::class));
        if ($this->app->bound(OwnershipAdoptionRegistry::class)) {
            $this->app->make(OwnershipAdoptionRegistry::class)->register('taxonomy', OwnershipTaxonomyAdoptionAdapter::class);
        }
        $this->app->make(LegacyTaxonomyIntegration::class)->bootScopes();
        foreach ($taxonomies->all() as $definition) {
            $unknownOwners = $owners->unknownReferences($definition->allowedOwners);

            if ($unknownOwners !== []) {
                throw new InvalidArgumentException(
                    "Taxonomy [{$definition->taxonomy}] references unknown owner aliases: "
                    .implode(', ', $unknownOwners).'.',
                );
            }
        }

        $typeScriptSources->register(__DIR__.'/..', 'nvl/taxonomy');

        $this->publishes([
            __DIR__.'/../../config/nvl-taxonomy.php' => config_path('nvl-taxonomy.php'),
        ], 'taxonomy-config');

        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'taxonomy-migrations');
        if ((bool) config('nvl-taxonomy.migrations.enabled', true)) {
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
            modelClass: $this->app->make(TermModelRegistry::class)->termClass(),
            label: 'Taxonomy terms',
            searchableColumns: ['taxonomy', 'slug'],
            displayColumns: ['taxonomy', 'slug'],
            orderColumn: 'position',
        );
    }
}
