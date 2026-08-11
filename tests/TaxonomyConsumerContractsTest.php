<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Nvl\Taxonomy\Actions\SyncTermAttachmentsAction;
use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Taxonomy\Exceptions\UnknownTaxonomyException;
use Nvl\Taxonomy\Models\Category;
use Nvl\Taxonomy\Models\Tag;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TaxonomyDoctor;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Taxonomy\Support\SlugGenerator;
use Nvl\Taxonomy\Support\TaxonomyDefinition;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Taxonomy\Tests\Fixtures\CustomKeyPost;
use Nvl\Taxonomy\Tests\Fixtures\Post;

it('supports the public owner query and inspection workflow', function () {
    $categorized = Post::create(['title' => 'Categorized']);
    $uncategorized = Post::create(['title' => 'Uncategorized']);
    $parent = Category::create(['slug' => 'guides']);
    $child = Category::create(['slug' => 'laravel', 'parent_id' => $parent->id]);

    app(SyncTermAttachmentsAction::class)->execute($categorized, 'tag', ['php', 'laravel']);
    app(SyncTermAttachmentsAction::class)->execute($categorized, 'category', [$child]);

    $tag = Tag::query()->where('slug', 'php')->firstOrFail();
    $freshOwner = $categorized->fresh();
    $pivotCount = $categorized->tags()->newPivotQuery()->count();
    $constrainedPivotSql = $categorized->tags()
        ->wherePivot('position', 0)
        ->wherePivotIn('position', [0, 1])
        ->wherePivotNull('updated_at')
        ->newPivotQuery()
        ->toSql();

    expect(Post::query()->withAnyTerms('tag', [])->count())->toBe(0)
        ->and(Post::query()->withAllTerms('tag', [])->count())->toBe(2)
        ->and(Post::query()->withoutTerms('tag', [])->count())->toBe(2)
        ->and(Post::query()->withAnyTerms('tag', [$tag->id, 'missing'])->sole()->is($categorized))->toBeTrue()
        ->and(Post::query()->withAllTerms('tag', ['php', 'laravel'])->sole()->is($categorized))->toBeTrue()
        ->and(Post::query()->withoutTerms('tag', ['php'])->sole()->is($uncategorized))->toBeTrue()
        ->and(Post::query()->inCategory($parent)->sole()->is($categorized))->toBeTrue()
        ->and(Post::query()->inCategory($parent, false)->count())->toBe(0)
        ->and($freshOwner->hasTerm('tag', $tag))->toBeTrue()
        ->and($freshOwner->hasTerm('tag', 'missing'))->toBeFalse()
        ->and($categorized->load('tags')->hasTerm('tag', $tag->id))->toBeTrue()
        ->and($categorized->hasTerm('tag', $tag))->toBeTrue()
        ->and($categorized->hasTerm('category', $tag))->toBeFalse()
        ->and($categorized->termables()->count())->toBe(3)
        ->and($pivotCount)->toBe(2)
        ->and($constrainedPivotSql)->toContain('position', 'updated_at', 'termable_id');

    $categorized->setRelation('tags', new stdClass);

    expect($categorized->hasTerm('tag', $tag))->toBeFalse();

    config()->set('taxonomy.limits.bulk_terms', 1);

    expect(fn () => Post::query()->withAnyTerms('tag', ['one', 'two'])->get())
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => Post::query()->withAllTerms('tag', ['one', 'two'])->get())
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => Post::query()->withoutTerms('tag', ['one', 'two'])->get())
        ->toThrow(InvalidArgumentException::class);
});

it('executes maintenance commands through dry run locked and mutation paths', function () {
    $source = Tag::create(['name' => 'Source', 'slug' => 'source']);
    $destination = Tag::create(['name' => 'Destination', 'slug' => 'destination']);

    $this->artisan('nvl:taxonomy:merge', [
        'taxonomy' => 'tag',
        'source' => 'missing',
        'destination' => $destination->slug,
        '--force' => true,
    ])->assertFailed();

    $this->artisan('nvl:taxonomy:merge', [
        'taxonomy' => 'tag',
        'source' => $source->slug,
        'destination' => $source->slug,
        '--force' => true,
    ])->assertFailed();

    $this->artisan('nvl:taxonomy:merge', [
        'taxonomy' => 'tag',
        'source' => $source->id,
        'destination' => $destination->id,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect($source->fresh())->not->toBeNull();

    $this->artisan('nvl:taxonomy:merge', [
        'taxonomy' => 'tag',
        'source' => $source->slug,
        'destination' => $destination->slug,
        '--force' => true,
    ])->assertSuccessful();

    expect($source->fresh())->toBeNull()
        ->and($destination->fresh())->not->toBeNull();

    $first = Category::create(['slug' => 'first', 'position' => 8]);
    $second = Category::create(['slug' => 'second', 'position' => 4]);

    $this->artisan('nvl:taxonomy:rebuild', [
        'taxonomy' => 'category',
        '--dry-run' => true,
    ])->assertSuccessful();

    $rebuildLock = Cache::lock('nvl:taxonomy:rebuild', 3600);
    expect($rebuildLock->get())->toBeTrue();

    try {
        $this->artisan('nvl:taxonomy:rebuild', ['taxonomy' => 'category'])
            ->assertFailed();
    } finally {
        $rebuildLock->release();
    }

    $this->artisan('nvl:taxonomy:rebuild', ['taxonomy' => 'category'])
        ->assertSuccessful();

    expect($first->refresh()->position)->toBe(1)
        ->and($second->refresh()->position)->toBe(0);

    $this->artisan('nvl:taxonomy:prune', [
        'taxonomy' => 'category',
        '--dry-run' => true,
    ])->assertSuccessful();

    Tag::create(['slug' => 'locked-orphan']);
    $pruneLock = Cache::lock('nvl:taxonomy:prune', 3600);
    expect($pruneLock->get())->toBeTrue();

    try {
        $this->artisan('nvl:taxonomy:prune', [
            'taxonomy' => 'tag',
            '--force' => true,
        ])->assertFailed();
    } finally {
        $pruneLock->release();
    }

    $this->artisan('nvl:taxonomy:prune', [
        'taxonomy' => 'tag',
        '--force' => true,
        '--chunk' => 1,
    ])->assertSuccessful();

    expect(Tag::query()->count())->toBe(0);

    $this->artisan('nvl:taxonomy:doctor')->assertSuccessful();
    $this->artisan('nvl:taxonomy:doctor', [
        '--format' => 'json',
        '--strict' => true,
    ])->assertSuccessful();
});

it('rejects malformed configured and programmatic vocabulary definitions', function () {
    $invalidConfigurations = [
        'not-an-array',
        [0 => []],
        ['Invalid Alias' => []],
        ['probe' => ['model' => stdClass::class]],
        ['probe' => ['sort' => 'random']],
        ['probe' => ['hierarchical' => 'yes']],
        ['probe' => ['metadata_rules' => [0 => []]]],
        ['probe' => ['allowed_owners' => [42]]],
    ];

    foreach ($invalidConfigurations as $configuration) {
        config()->set('taxonomy.taxonomies', $configuration);

        expect(fn () => new TaxonomyRegistry)
            ->toThrow(InvalidArgumentException::class);
    }

    config()->set('taxonomy.taxonomies', []);
    $registry = new TaxonomyRegistry;
    $valid = new TaxonomyDefinition(
        taxonomy: 'probe',
        model: Term::class,
        hierarchical: false,
        exclusive: false,
        open: false,
        maxDepth: 0,
        sort: 'position',
        allowedOwners: [],
        metadataRules: [],
    );
    $registry->register($valid);

    expect($registry->get('probe'))->toBe($valid)
        ->and($registry->all())->toHaveKey('probe')
        ->and(fn () => $registry->register($valid))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->get('missing'))->toThrow(UnknownTaxonomyException::class)
        ->and(fn () => $registry->register(new TaxonomyDefinition(
            taxonomy: 'bad-model',
            model: stdClass::class,
            hierarchical: false,
            exclusive: false,
            open: false,
            maxDepth: 0,
            sort: 'position',
            allowedOwners: [],
            metadataRules: [],
        )))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register(new TaxonomyDefinition(
            taxonomy: 'bad-structure',
            model: Term::class,
            hierarchical: false,
            exclusive: false,
            open: false,
            maxDepth: -1,
            sort: 'random',
            allowedOwners: [],
            metadataRules: [],
        )))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register(new TaxonomyDefinition(
            taxonomy: 'bad-owners',
            model: Term::class,
            hierarchical: false,
            exclusive: false,
            open: false,
            maxDepth: 0,
            sort: 'position',
            allowedOwners: ['posts', 'posts'],
            metadataRules: [],
        )))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register(new TaxonomyDefinition(
            taxonomy: 'bad-metadata',
            model: Term::class,
            hierarchical: false,
            exclusive: false,
            open: false,
            maxDepth: 0,
            sort: 'position',
            allowedOwners: [],
            metadataRules: ['' => []],
        )))->toThrow(InvalidArgumentException::class);

    expect(MutateTermPayload::rules())
        ->toHaveKeys(['taxonomy', 'slug', 'translations', 'parentId', 'expectedRevision']);
});

it('enforces stable owner aliases at registration and provider resolution', function () {
    $registry = new TaxonomyOwnerRegistry;
    $registry->register('posts', Post::class);
    $registry->register('posts', Post::class);

    expect($registry->aliasFor(new Post))->toBe('posts')
        ->and($registry->all())->toBe(['posts' => Post::class])
        ->and(fn () => $registry->register('Invalid Alias', Post::class))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('invalid-model', stdClass::class))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('posts', CustomKeyPost::class))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('other-posts', Post::class))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new TaxonomyOwnerRegistry)->register('posts', CustomKeyPost::class))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new TaxonomyOwnerRegistry)->register('new-posts', Post::class))
        ->toThrow(InvalidArgumentException::class);

    expect(Relation::getMorphedModel('posts'))->toBe(Post::class);

    config()->set('taxonomy.owners', 'invalid');
    app()->forgetInstance(TaxonomyOwnerRegistry::class);
    expect(fn () => app(TaxonomyOwnerRegistry::class))
        ->toThrow(InvalidArgumentException::class);

    config()->set('taxonomy.owners', [0 => Post::class]);
    app()->forgetInstance(TaxonomyOwnerRegistry::class);
    expect(fn () => app(TaxonomyOwnerRegistry::class))
        ->toThrow(InvalidArgumentException::class);

    config()->set('taxonomy.slugs.generator', stdClass::class);
    app()->forgetInstance(SlugGenerator::class);
    expect(fn () => app(SlugGenerator::class))
        ->toThrow(InvalidArgumentException::class);
});

it('reports missing consumer schema without attempting data inspection', function () {
    config()->set('taxonomy.table_names', [
        'terms' => 'missing_terms',
        'terms_i18n' => 'missing_terms_i18n',
        'termables' => 'missing_termables',
    ]);

    $checks = (new TaxonomyDoctor(
        app(TaxonomyRegistry::class),
        app(TaxonomyOwnerRegistry::class),
    ))->inspect();

    expect(collect($checks)->where('passed', false))->toHaveCount(9)
        ->and(collect($checks)->pluck('key')->all())->toContain(
            'schema.missing_terms',
            'indexes.missing_terms',
            'foreign_keys.missing_termables',
        );
});
