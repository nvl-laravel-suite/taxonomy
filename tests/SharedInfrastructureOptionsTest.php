<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Taxonomy\Actions\SyncTermAttachmentsAction;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Taxonomy\Tests\TestCase;

uses(TestCase::class);

it('uses the inherited Core store for taxonomy attachment locking', function (): void {
    config([
        'nvl-taxonomy.locks.store' => null,
        'nvl-core.locks.store' => 'shared-taxonomy-locks',
        'cache.stores.shared-taxonomy-locks' => ['driver' => 'array'],
    ]);
    $repository = Cache::store('shared-taxonomy-locks');
    Cache::shouldReceive('store')->with('shared-taxonomy-locks')->once()->andReturn($repository);
    $owner = Post::create(['title' => 'Categorized']);
    app(SyncTermAttachmentsAction::class)->execute($owner, 'tag', ['shared']);

    expect($owner->tags()->sole()->slug)->toBe('shared')
        ->and(TaxonomyConfiguration::lockStore())->toBe('shared-taxonomy-locks');
});

it('preserves foreign attachment locks while serializing the owned vocabulary set', function (): void {
    config()->set('nvl-taxonomy.locks.wait_seconds', 1);
    $owner = Post::create(['title' => 'Namespaced vocabulary']);
    $identity = hash('sha256', implode('|', [$owner->getMorphClass(), (string) $owner->getKey(), 'tag']));
    $foreignKey = 'attachments:'.$identity;
    $foreignLock = Cache::store(TaxonomyConfiguration::lockStore())->lock($foreignKey, 30);
    expect($foreignLock->get())->toBeTrue();

    try {
        app(SyncTermAttachmentsAction::class)->execute($owner, 'tag', ['namespaced']);
        expect($owner->tags()->sole()->slug)->toBe('namespaced')
            ->and(TaxonomyConfiguration::attachmentLockName(app(TenantBoundary::class), $owner, 'tag'))
            ->toBe('nvl:taxonomy:attachments:'.$identity)
            ->and(Cache::store(TaxonomyConfiguration::lockStore())->lock($foreignKey, 30)->get())->toBeFalse();
    } finally {
        $foreignLock->release();
    }
});
