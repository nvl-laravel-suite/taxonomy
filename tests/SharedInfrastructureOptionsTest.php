<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Nvl\Taxonomy\Actions\SyncTermAttachmentsAction;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Taxonomy\Tests\TestCase;

uses(TestCase::class);

it('uses the inherited Core store for taxonomy attachment locking', function (): void {
    config([
        'taxonomy.locks.store' => null,
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
