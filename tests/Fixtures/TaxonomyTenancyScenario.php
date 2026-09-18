<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Model;
use Nvl\Taxonomy\Actions\CreateTermAction;
use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Taxonomy\Models\Term;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Installs and exercises Taxonomy through the real tenancy coordinator. */
final readonly class TaxonomyTenancyScenario
{
    public const string A = '00000000-0000-4000-8000-00000000000a';

    public const string B = '00000000-0000-4000-8000-00000000000b';

    /** Adopt Taxonomy plus its canonical owner fixture. */
    public static function install(bool $catalogCopies = false): self
    {
        expect($catalogCopies)->toBeFalse()
            ->and(config('tenancy.enabled'))->toBeTrue();
        $operation = new PlatformOperation('fixture.adoption', 'test', 'fixture');
        $coordinator = app(TenantAdoptionCoordinator::class);
        $plan = $coordinator->prepare(['resource-fixture-owners', 'taxonomy'], [], $operation);
        $done = false;
        for ($batch = 0; $batch < 20 && ! $done; $batch++) {
            $done = $coordinator->backfill($plan, 100, $operation);
        }
        expect($done)->toBeTrue()
            ->and($coordinator->verify($plan)->passed())->toBeTrue();
        $coordinator->activate($plan, $operation);
        app(MaintenanceMode::class)->deactivate();

        return new self;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(string $tenant, Closure $callback): mixed
    {
        return app(TenantRunner::class)->run(new TenantId($tenant), $callback);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function platform(Closure $callback): mixed
    {
        return app(TenantRunner::class)->platform(new PlatformOperation('fixture.catalog', 'test', 'fixture'), $callback);
    }

    /** Create one registered canonical taxonomy owner. */
    public function owner(string $tenant): Model
    {
        return $this->run($tenant, static function (): Model {
            $owner = new Post(['title' => 'Owner']);
            $owner->forceFill(app(TenantBoundary::class)->attributes('test.taxonomy-owners'));
            $owner->save();

            return $owner->refresh();
        });
    }

    /** Create one tenant-local translated root term. */
    public function term(string $tenant, string $slug = 'shared', string $taxonomy = 'tag'): Term
    {
        return $this->run($tenant, static fn (): Term => app(CreateTermAction::class)->execute(
            MutateTermPayload::from([
                'taxonomy' => $taxonomy,
                'slug' => $slug,
                'translations' => ['en' => ['name' => ucfirst($slug)]],
            ]),
        ));
    }
}
