<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Exceptions\CircularHierarchyException;
use Nvl\Taxonomy\Exceptions\ClosedVocabularyException;
use Nvl\Taxonomy\Exceptions\InvalidParentException;
use Nvl\Taxonomy\Exceptions\StaleTermVersionException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TaxonomyAssignments;
use Nvl\Taxonomy\Services\TaxonomyReader;
use Nvl\Taxonomy\Services\TaxonomyTerms;
use Nvl\Taxonomy\Tests\Fixtures\Post;

it('keeps native term writes atomic and rejects cycles', function (): void {
    $terms = app(TaxonomyTerms::class);
    $actor = SystemActor::Background;
    $root = $terms->create(['taxonomy' => 'category', 'slug' => 'news', 'translations' => ['en' => ['name' => 'News']]], $actor);
    $child = $terms->create(['taxonomy' => 'category', 'slug' => 'local', 'parent_id' => $root->id, 'translations' => ['en' => ['name' => 'Local']]], $actor);

    expect($root->displayName('en'))->toBe('News')
        ->and($child->parent_id)->toBe($root->id);

    expect(fn () => $terms->move($root, $child, $actor, 0, $root->revision))
        ->toThrow(CircularHierarchyException::class);

    expect($root->fresh()->parent_id)->toBeNull();
});

it('rejects cross vocabulary moves and stale merges while retaining localized copy', function (): void {
    config()->set('nvl-translatable.locales', ['en', 'bg']);
    $terms = app(TaxonomyTerms::class);
    $actor = SystemActor::Background;
    $category = $terms->create(['taxonomy' => 'category', 'slug' => 'stable', 'translations' => ['en' => ['name' => 'Stable'], 'bg' => ['name' => 'Устойчив']]], $actor);
    $tag = $terms->create(['taxonomy' => 'tag', 'slug' => 'other', 'translations' => ['en' => ['name' => 'Other']]], $actor);
    $destination = $terms->create(['taxonomy' => 'category', 'slug' => 'destination', 'translations' => ['en' => ['name' => 'Destination']]], $actor);

    expect($category->displayName('bg'))->toBe('Устойчив')
        ->and($category->slug)->toBe('stable');
    expect(fn () => $terms->move($category, $tag, $actor, 0, $category->revision))
        ->toThrow(InvalidParentException::class);
    expect(fn () => $terms->merge($category, $destination, $actor, 0, $destination->revision))
        ->toThrow(StaleTermVersionException::class);
    expect($category->fresh())->not->toBeNull();
});

it('synchronizes native assignments and returns explicit ordered owner batches', function (): void {
    $actor = SystemActor::Background;
    $owner = Post::create(['title' => 'Native owner']);
    $assignments = app(TaxonomyAssignments::class);
    $assignments->sync($owner, 'tag', ['first', 'second'], $actor);

    $batch = app(TaxonomyReader::class)->forOwners([$owner], ['tag'], $actor, 'en');
    $visible = $batch['owners']->{$owner->getMorphClass()}->{(string) $owner->getKey()}->tag;

    expect($batch['order'])->toBe([['type' => $owner->getMorphClass(), 'id' => (string) $owner->getKey()]])
        ->and($visible)->toHaveCount(2)
        ->and($visible[0])->toBeInstanceOf(Term::class)
        ->and($visible[0]->slug)->toBe('first')
        ->and($visible[1]->slug)->toBe('second');

    $assignments->sync($owner, 'tag', [], $actor);
    $empty = app(TaxonomyReader::class)->forOwners([$owner], ['tag'], $actor, 'en');
    expect($empty['owners']->{$owner->getMorphClass()}->{(string) $owner->getKey()}->tag)->toBe([]);
});

it('rolls back an invalid ordered assignment sync', function (): void {
    $actor = SystemActor::Background;
    $owner = Post::create(['title' => 'Rollback owner']);
    $terms = app(TaxonomyTerms::class);
    $one = $terms->create(['taxonomy' => 'category', 'slug' => 'one', 'translations' => ['en' => ['name' => 'One']]], $actor);
    app(TaxonomyAssignments::class)->sync($owner, 'category', [$one], $actor);

    expect(fn () => app(TaxonomyAssignments::class)->sync($owner, 'category', [$one, 'unknown'], $actor))
        ->toThrow(ClosedVocabularyException::class);
    expect($owner->fresh()->categories()->pluck('slug')->all())->toBe(['one']);
});

it('requires explicit permission for a background taxonomy mutation', function (): void {
    Gate::define('taxonomy.create', static fn (): bool => false);

    expect(fn () => app(TaxonomyTerms::class)->create([
        'taxonomy' => 'tag', 'slug' => 'denied', 'translations' => ['en' => ['name' => 'Denied']],
    ], SystemActor::Background))->toThrow(AuthorizationException::class);
    expect(Term::query()->count())->toBe(0);
});

it('rejects a missing taxonomy before resolving its model', function (): void {
    Gate::define('taxonomy.create', static fn (): bool => true);

    expect(fn () => app(TaxonomyTerms::class)->create([], SystemActor::Background))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a forged actor source before querying it', function (): void {
    $actor = new User;
    $actor->forceFill(['id' => 1])->syncOriginal();
    $actor->exists = true;
    $actor->setTable('forged_taxonomy_actor_source');

    expect(fn () => app(TaxonomyTerms::class)->create([
        'taxonomy' => 'tag', 'slug' => 'forged',
    ], $actor))->toThrow(AuthorizationException::class);
});
