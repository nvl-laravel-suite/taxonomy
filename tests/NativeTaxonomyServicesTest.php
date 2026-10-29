<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization;
use Nvl\Taxonomy\Contracts\TaxonomyCatalogAuthorization;
use Nvl\Taxonomy\Exceptions\CircularHierarchyException;
use Nvl\Taxonomy\Exceptions\ClosedVocabularyException;
use Nvl\Taxonomy\Exceptions\InvalidParentException;
use Nvl\Taxonomy\Exceptions\StaleTermVersionException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TaxonomyAssignments;
use Nvl\Taxonomy\Services\TaxonomyReader;
use Nvl\Taxonomy\Services\TaxonomyTerms;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Translatable\Enums\TranslationSyncMode;

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

it('passes the locked canonical owner and vocabulary to assignment authorization', function (): void {
    $owner = Post::create(['title' => 'Allowed owner']);
    $foreign = Post::create(['title' => 'Foreign owner']);
    $owner->title = 'Forged in memory';
    Gate::define('taxonomy.assign', static fn (mixed $actor, Post $canonical, string $taxonomy): bool => $actor === SystemActor::Background
        && $canonical->id === $owner->id && $canonical->title === 'Allowed owner' && $taxonomy === 'tag');

    $assignments = app(TaxonomyAssignments::class);
    expect(fn () => $assignments->sync($foreign, 'tag', ['denied'], SystemActor::Background))
        ->toThrow(AuthorizationException::class);
    $assignments->sync($owner, 'tag', ['admitted'], SystemActor::Background);
    expect($owner->fresh()->tags()->pluck('slug')->all())->toBe(['admitted'])
        ->and($foreign->fresh()->tags()->count())->toBe(0);
});

it('passes locked canonical terms and related targets to mutation policies', function (string $operation): void {
    $terms = app(TaxonomyTerms::class);
    $source = $terms->create(['taxonomy' => 'category', 'slug' => 'source', 'translations' => ['en' => ['name' => 'Source']]], SystemActor::Background);
    $destination = $terms->create(['taxonomy' => 'category', 'slug' => 'destination', 'translations' => ['en' => ['name' => 'Destination']]], SystemActor::Background);
    $source->slug = 'forged';
    $source->setRelation('forged', collect());
    $calls = 0;
    Gate::define('taxonomy.'.($operation === 'validateMerge' ? 'merge' : $operation), function (mixed $actor, ?Term $current = null, ?string $taxonomy = null, ?Term $related = null) use ($source, $destination, $operation, &$calls): bool {
        $calls++;
        expect($current?->id)->toBe($source->id)
            ->and($current?->slug)->toBe('source')
            ->and($taxonomy)->toBe('category')
            ->and($current->getConnection()->transactionLevel())->toBeGreaterThan(1);
        if (in_array($operation, ['move', 'merge', 'validateMerge'], true)) {
            expect($related?->id)->toBe($destination->id);
        }

        return true;
    });

    match ($operation) {
        'update' => $terms->update($source, ['translations' => ['en' => ['name' => 'Updated']], 'expected_revision' => $source->revision], SystemActor::Background),
        'delete' => $terms->delete($source, $source->revision, SystemActor::Background),
        'move' => $terms->move($source, $destination, SystemActor::Background, 0, $source->revision),
        'merge' => $terms->merge($source, $destination, SystemActor::Background, $source->revision, $destination->revision),
        'validateMerge' => $terms->validateMerge($source, $destination, SystemActor::Background, $source->revision, $destination->revision),
    };
    expect($calls)->toBe(1);
})->with(['update', 'delete', 'move', 'merge', 'validateMerge']);

it('requires create permission when resolving or assigning a missing open term', function (string $operation): void {
    Gate::define('taxonomy.create', static fn (): bool => false);
    $owner = Post::create(['title' => 'Owner']);

    expect(fn () => $operation === 'resolve'
        ? app(TaxonomyReader::class)->resolve('tag', ['new-term'], SystemActor::Background)
        : app(TaxonomyAssignments::class)->sync($owner, 'tag', ['new-term'], SystemActor::Background))
        ->toThrow(AuthorizationException::class);
    expect(Term::query()->count())->toBe(0)
        ->and($owner->termables()->count())->toBe(0);
})->with(['resolve', 'assign']);

it('assigns existing open terms without create permission and keeps repeated appends idempotent at the bound', function (): void {
    $term = app(TaxonomyTerms::class)->create(['taxonomy' => 'tag', 'slug' => 'existing', 'translations' => ['en' => ['name' => 'Existing']]], SystemActor::Background);
    $owner = Post::create(['title' => 'Owner']);
    config(['nvl-taxonomy.limits.bulk_terms' => 1]);
    Gate::define('taxonomy.create', static fn (): bool => false);
    $assignments = app(TaxonomyAssignments::class);
    $assignments->sync($owner, 'tag', [$term], SystemActor::Background);
    $assignments->attach($owner, 'tag', [$term], SystemActor::Background);

    expect($owner->fresh()->tags()->pluck($term->qualifyColumn('id'))->all())->toBe([$term->id]);
});

it('passes the requested vocabulary to create authorization', function (): void {
    Gate::define('taxonomy.create', static fn (mixed $actor, string $taxonomy): bool => $taxonomy === 'tag');
    $terms = app(TaxonomyTerms::class);
    $tag = $terms->create(['taxonomy' => 'tag', 'slug' => 'allowed', 'translations' => ['en' => ['name' => 'Allowed']]], SystemActor::Background);

    expect(fn () => $terms->create(['taxonomy' => 'category', 'slug' => 'denied', 'translations' => ['en' => ['name' => 'Denied']]], SystemActor::Background))
        ->toThrow(AuthorizationException::class);
    expect(Term::query()->pluck('id')->all())->toBe([$tag->id]);
});

it('preserves translations when updating only structural fields', function (): void {
    $terms = app(TaxonomyTerms::class);
    $term = $terms->create(['taxonomy' => 'tag', 'slug' => 'original', 'translations' => ['en' => ['name' => 'Retained', 'description' => 'Copy']]], SystemActor::Background);
    $updated = $terms->update($term, ['position' => 4, 'expected_revision' => $term->revision], SystemActor::Background);

    expect($updated->position)->toBe(4)
        ->and($updated->displayName('en'))->toBe('Retained')
        ->and($updated->displayDescription('en'))->toBe('Copy')
        ->and($updated->revision)->toBe($term->revision + 1);
});

it('rejects an explicit null position instead of resetting it', function (): void {
    $terms = app(TaxonomyTerms::class);
    $term = $terms->create(['taxonomy' => 'tag', 'slug' => 'position', 'position' => 4, 'translations' => ['en' => ['name' => 'Position']]], SystemActor::Background);

    expect(fn () => $terms->update($term, ['position' => null, 'translations' => ['en' => ['name' => 'Position']], 'expected_revision' => $term->revision], SystemActor::Background))
        ->toThrow(InvalidArgumentException::class);
    expect($term->fresh()->position)->toBe(4);
});

it('requires an explicit locale map for translation replacement', function (): void {
    $terms = app(TaxonomyTerms::class);
    $term = $terms->create(['taxonomy' => 'tag', 'slug' => 'retained-copy', 'translations' => ['en' => ['name' => 'Retained']]], SystemActor::Background);

    expect(fn () => $terms->update($term, ['position' => 2, 'expected_revision' => $term->revision], SystemActor::Background, TranslationSyncMode::Replace))
        ->toThrow(InvalidArgumentException::class);
    expect($term->fresh()->position)->toBe(0)
        ->and($term->fresh()->displayName('en'))->toBe('Retained');
});

it('rejects substituted owner identities before assigning terms', function (string $mutation): void {
    $owner = Post::create(['title' => 'Owner']);
    $other = Post::create(['title' => 'Other']);
    match ($mutation) {
        'table' => $owner->setTable('another_table'),
        'key' => $owner->id = $other->id,
        'connection' => $owner->setConnection('missing_connection'),
    };

    expect(fn () => app(TaxonomyAssignments::class)->sync($owner, 'tag', ['new-term'], SystemActor::Background))
        ->toThrow(OwnershipViolation::class);
    expect(Term::query()->count())->toBe(0);
})->with(['table', 'key', 'connection']);

it('passes the fresh explicit actor to every batch policy callback', function (): void {
    $actor = new class extends User
    {
        protected $table = 'taxonomy_posts';
    };
    $actor->forceFill(['title' => 'Persisted'])->save();
    $owner = Post::create(['title' => 'Owner']);
    app(TaxonomyAssignments::class)->sync($owner, 'tag', ['existing'], SystemActor::Background);
    $actor->title = 'Forged';
    Gate::define('taxonomy.read', static fn (User $current): bool => $current->title === 'Persisted');
    $policy = Mockery::mock(TaxonomyBatchAuthorization::class);
    foreach (['authorizeOwners', 'scopeTerms', 'scopeAttachments', 'scopeHostTerms'] as $method) {
        $policy->shouldReceive($method)->atLeast()->once()->andReturnUsing(function (...$arguments) use ($actor): void {
            $current = $arguments[0];
            expect($current)->toBeInstanceOf($actor::class)
                ->and($current->getKey())->toBe($actor->getKey())
                ->and($current->title)->toBe('Persisted')
                ->and($current->getRelations())->toBe([]);
        });
    }
    app()->instance(TaxonomyBatchAuthorization::class, $policy);

    expect(app(TaxonomyReader::class)->forOwners([$owner], ['tag'], $actor)['order'])->toHaveCount(1);
    expect(Post::query()->withAnyTerms('tag', ['existing'], $actor, $policy)->pluck('id')->all())->toBe([$owner->id]);
});

it('passes the fresh explicit actor to catalog policies for all read entry points', function (string $operation): void {
    $actor = new class extends User
    {
        protected $table = 'taxonomy_posts';
    };
    $actor->forceFill(['title' => 'Persisted'])->save();
    $actor->title = 'Forged';
    foreach (['read', 'resolve'] as $ability) {
        Gate::define('taxonomy.'.$ability, static fn (User $current): bool => $current->title === 'Persisted');
    }
    $policy = Mockery::mock(TaxonomyCatalogAuthorization::class);
    $policy->shouldReceive('authorizeVocabulary')->once()->withArgs(function (...$arguments) use ($actor): bool {
        return count($arguments) === 2 && $arguments[0] instanceof $actor && $arguments[0]->title === 'Persisted' && $arguments[1] === 'tag';
    });
    app()->instance(TaxonomyCatalogAuthorization::class, $policy);
    $reader = app(TaxonomyReader::class);
    match ($operation) {
        'tree' => $reader->tree('tag', $actor),
        'term' => $reader->term('tag', '01900000-0000-7000-8000-000000000001', $actor),
        'resolve' => $reader->resolve('tag', [], $actor),
    };
})->with(['tree', 'term', 'resolve']);

it('bounds root resolution before any term writes', function (array $references): void {
    config(['nvl-taxonomy.limits.bulk_terms' => 1]);

    expect(fn () => app(TaxonomyReader::class)->resolve('tag', $references, SystemActor::Background))
        ->toThrow(InvalidArgumentException::class);
    expect(Term::query()->count())->toBe(0);
})->with([
    'too many inputs' => [['first', 'second']],
    'non-list inputs' => [['unexpected' => 'first']],
]);
