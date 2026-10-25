<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Taxonomy\Actions\AttachTermsAction;
use Nvl\Taxonomy\Actions\MergeTermsAction;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Services\TaxonomyDoctor;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Taxonomy\Tests\Fixtures\TaxonomyTenancyScenario;
use Nvl\Taxonomy\Tests\MappedOwnerTenancyTestCase;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantId;

uses(MappedOwnerTenancyTestCase::class);

it('queries checks and merges mapped class-list Taxonomy attachments with restricted owners', function (): void {
    $scenario = TaxonomyTenancyScenario::install();
    $owner = $scenario->owner($scenario::A);
    $source = $scenario->term($scenario::A, 'source');
    $target = $scenario->term($scenario::A, 'target');
    $scenario->run($scenario::A, static fn () => app(AttachTermsAction::class)->execute($owner, 'tag', [$source]));
    $attachment = $scenario->run($scenario::A, static fn () => Termable::query()->sole());

    expect(app(TaxonomyOwnerRegistry::class)->types())->toBe(['host.taxonomy-owner' => Post::class])
        ->and($attachment->termable_type)->toBe('host.taxonomy-owner')
        ->and($scenario->run($scenario::B, static fn (): int => Termable::query()->count()))->toBe(0)
        ->and(fn () => $scenario->run($scenario::B, static fn () => app(TenantBoundary::class)->assertRecord($attachment, 'taxonomy.attachments')))
        ->toThrow(TenantBoundaryViolation::class);

    $scenario->run($scenario::A, static fn () => app(MergeTermsAction::class)->execute($source, $target, $source->revision, $target->revision));
    expect($scenario->run($scenario::A, static fn () => Termable::query()->sole()->term_id))->toBe($target->id)
        ->and(collect(app(TaxonomyDoctor::class)->inspect())->firstWhere('key', 'registry.allowed_owners')->passed)->toBeTrue();
});

it('adopts existing native mapped attachments using registered class-list owners', function (): void {
    $operation = new PlatformOperation('fixture.adoption', 'test', 'fixture');
    $termId = (string) Str::uuid();
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['resource-fixture-owners', 'taxonomy'], [
        new TenantAssignment('taxonomy.terms', $termId, new TenantId(TaxonomyTenancyScenario::A)),
    ], $operation);
    $ownerId = DB::table('taxonomy_posts')->insertGetId([
        'tenant_id' => TaxonomyTenancyScenario::A, 'title' => 'Existing owner',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('tenant_terms')->insert([
        'id' => $termId, 'taxonomy' => 'tag', 'slug' => 'existing',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('tenant_termables')->insert([
        'id' => (string) Str::uuid(), 'term_id' => $termId, 'taxonomy' => 'tag',
        'termable_type' => 'host.taxonomy-owner', 'termable_id' => (string) $ownerId,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $done = false;
    for ($batch = 0; $batch < 20 && ! $done; $batch++) {
        $done = $coordinator->backfill($plan, 100, $operation);
    }
    expect($done)->toBeTrue()->and($coordinator->verify($plan)->passed())->toBeTrue();
    $coordinator->activate($plan, $operation);
    app(MaintenanceMode::class)->deactivate();
    $scenario = new TaxonomyTenancyScenario;
    expect($scenario->run($scenario::A, static fn () => Termable::query()->sole()->tenant_id))->toBe($scenario::A)
        ->and($scenario->run($scenario::B, static fn (): int => Termable::query()->count()))->toBe(0);
});
