<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Ownership\Contracts\CentralAccess;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Providers\OwnershipServiceProvider;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\OwnershipStorageTables;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Providers\TaxonomyServiceProvider;
use Nvl\Taxonomy\Services\TermModelRegistry;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipActor;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipCompany;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipTaxonomyOwner;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipTaxonomyOwnerAdapter;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipTerm;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase;

/** Exercises committed ownership and audit behavior without NVL Tenancy or Auth providers. */
abstract class OwnershipTestCase extends TestCase
{
    private array $originalMorphMap = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        Relation::morphMap($this->originalMorphMap, false);
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [LocaleServiceProvider::class, SupportServiceProvider::class, TranslatableServiceProvider::class, DataServiceProvider::class, OwnershipServiceProvider::class, TaxonomyServiceProvider::class];
    }

    /** @return class-string<Term> */
    protected function termClass(): string
    {
        return OwnershipTerm::class;
    }

    /** Configure owned package roots before immutable resource registration. */
    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'nvl-core.owners' => [OwnershipCompany::class, OwnershipActor::class],
            'nvl-ownership.enabled' => true, 'nvl-ownership.migrations.enabled' => false,
            'nvl-ownership.central_operations' => ['taxonomy-maintain'],
            'nvl-tenancy.enabled' => false,
            'nvl-taxonomy.models.term' => $this->termClass(),
            'nvl-taxonomy.ownership.owner_resources' => ['fixture.taxonomy-owners'],
            'nvl-taxonomy.taxonomies' => ['category' => ['hierarchical' => true, 'exclusive' => false, 'open' => true, 'max_depth' => 5], 'tag' => ['hierarchical' => false, 'exclusive' => false, 'open' => true]],
            'nvl-taxonomy.owners' => ['subjects' => OwnershipTaxonomyOwner::class],
            'nvl-translatable.locales' => ['en', 'bg'],
            'nvl-comments.moderation.new_status' => 'approved',
            'nvl-comments.mutation_lock.enabled' => false,
            'nvl-taxonomy.migrations.enabled' => false,
            'nvl-media.deduplication_lock.store' => 'array',
            'nvl-media.routes.api_enabled' => false,
            'auth.providers.users.model' => OwnershipActor::class,
        ]);
        if (getenv('NVL_OWNERSHIP_TEST_DRIVER') === 'pgsql') {
            $database = getenv('NVL_OWNERSHIP_TEST_DATABASE');
            if (! is_string($database) || preg_match('/^nvl_own_test_[a-z0-9_]+$/D', $database) !== 1) {
                throw new \RuntimeException('Native integration tests require a dedicated nvl_own_test_ database.');
            }
            $app['config']->set('database.connections.sqlite', ['driver' => 'pgsql', 'url' => null, 'host' => '/tmp', 'port' => '5432', 'database' => $database, 'username' => get_current_user(), 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer']);
        }
    }

    /** Install real package storage and activate through the reviewed coordinator. */
    protected function setUp(): void
    {
        $this->originalMorphMap = Relation::morphMap();
        parent::setUp();
        $connection = TermModelRegistry::newInstance()->getConnection();
        $schema = $connection->getSchemaBuilder();
        $actorSchema = DB::getSchemaBuilder();
        if (getenv('NVL_OWNERSHIP_TEST_DRIVER') === 'pgsql') {
            if (DB::connection()->getDatabaseName() !== getenv('NVL_OWNERSHIP_TEST_DATABASE')) {
                throw new \RuntimeException('The actual coordinator differs from the dedicated test database.');
            }
            $actorSchema->dropAllTables();
            if ($connection !== DB::connection()) {
                if ($connection->getDatabaseName() !== getenv('NVL_OWNERSHIP_TEST_REMOTE_DATABASE')) {
                    throw new \RuntimeException('The actual source differs from the dedicated remote test database.');
                }
                $schema->dropAllTables();
            }
        }
        $schema->create('taxonomy_owned_companies', static fn (Blueprint $table) => $table->unsignedBigInteger('id')->primary());
        $actorSchema->create('taxonomy_owned_actors', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
        });
        OwnershipCompany::query()->insert([['id' => 7], ['id' => 8]]);
        OwnershipActor::query()->create(['id' => 1, 'name' => 'Operator', 'email' => 'operator@example.test', 'password' => 'private hash']);
        $schema->create('taxonomy_owned_subjects', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->foreign('company_id')->references('id')->on('taxonomy_owned_companies');
        });
        $this->app->make(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('fixture.taxonomy-owners', 'fixture', OwnershipTaxonomyOwner::class, OwnershipTaxonomyOwner::ownershipDefinition()));
        $this->app->make(OwnershipAdoptionRegistry::class)->register('fixture', OwnershipTaxonomyOwnerAdapter::class);
        foreach (glob(__DIR__.'/../database/migrations/*.php') ?: [] as $path) {
            (require $path)->up();
        }
        $definitionTable = (new ($this->termClass()))->getTable();
        $schema->table($definitionTable, static function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('taxonomy_owned_companies');
        });
        if ($this->termClass()::ownershipDefinition()->strategy === 'pivot') {
            $schema->create('taxonomy_term_owners', static function (Blueprint $table) use ($definitionTable): void {
                $table->uuid('term_id');
                $table->unsignedBigInteger('company_id');
                $table->foreign('term_id')->references('id')->on($definitionTable)->cascadeOnDelete();
                $table->foreign('company_id')->references('id')->on('taxonomy_owned_companies');
            });
        }
        (require __DIR__.'/../../ownership/database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
        if ($connection !== DB::connection() && ! $schema->hasTable(OwnershipStorageTables::Stores)) {
            $configured = config('nvl-ownership.connection');
            try {
                config(['nvl-ownership.connection' => $connection->getName()]);
                (require __DIR__.'/../../ownership/database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
            } finally {
                config(['nvl-ownership.connection' => $configured]);
            }
        }

        $this->app->bind(OwnerAccess::class, static fn () => new class implements OwnerAccess
        {
            public function assertCanEnter(Authenticatable $actor, Model $owner): void {}
        });
        $this->app->bind(CentralAccess::class, static fn () => new class implements CentralAccess
        {
            public function assertCanRun(string $operation, Authenticatable $actor): void {}
        });
        $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
        {
            public function authorize(OwnershipOperation $operation): void {}
        });
        $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            public function activate(array $payload): void {}

            public function deactivate(): void {}

            public function active(): bool
            {
                return true;
            }

            public function data(): array
            {
                return [];
            }
        });
        $this->adoptOwnership();
    }

    /** Activate this fixture graph after its source tables and host policies exist. */
    protected function adoptOwnership(): void
    {
        $operation = new OwnershipOperation('adopt-taxonomy', 'operator', '1');
        $coordinator = $this->app->make(OwnershipAdoptionCoordinator::class);
        $plan = $coordinator->prepare(['taxonomy', 'fixture'], $this->reviewedMappings(), $operation);
        $coordinator->backfill($plan, 100, $operation);
        $coordinator->activate($plan, $operation);
    }

    /** @return list<OwnershipMapping> */
    protected function reviewedMappings(): array
    {
        return [];
    }
}
