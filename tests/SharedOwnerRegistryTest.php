<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Taxonomy\Tests\Fixtures\CustomKeyPost;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Taxonomy\Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->originalOwnerMorphMap = Relation::morphMap();
});

afterEach(function (): void {
    Relation::morphMap($this->originalOwnerMorphMap, false);
});

it('resolves shared taxonomy references without registering other core owners', function (): void {
    config()->set('nvl-core.owners', ['posts' => Post::class, 'custom_key_posts' => CustomKeyPost::class]);
    $registry = app()->build(TaxonomyOwnerRegistry::class);
    $registry->register('posts', 'posts');

    expect($registry->aliasFor(new Post))->toBe('posts')
        ->and($registry->all())->toBe(['posts' => Post::class])
        ->and(fn () => $registry->aliasFor(new CustomKeyPost))->toThrow(InvalidArgumentException::class);
});

it('rejects taxonomy capability keys that differ from canonical morph identities', function (): void {
    config()->set('nvl-core.owners', ['posts' => Post::class]);

    expect(fn () => app()->build(TaxonomyOwnerRegistry::class)->register('posts.detail', 'posts'))
        ->toThrow(InvalidArgumentException::class);
});
