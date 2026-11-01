<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\OwnerRegistry;
use Nvl\Taxonomy\Exceptions\UnknownTaxonomyException;
use Nvl\Taxonomy\Models\Category;
use Nvl\Taxonomy\Models\Tag;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Ownership\TaxonomyBoundary;
use Nvl\Taxonomy\Services\TaxonomyAssignments;
use Nvl\Taxonomy\Services\TaxonomyDoctor;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Taxonomy\Services\TaxonomyTerms;
use Nvl\Taxonomy\Support\SlugGenerator;
use Nvl\Taxonomy\Support\TaxonomyDefinition;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Taxonomy\Tests\Fixtures\CustomKeyPost;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Taxonomy\Tests\Fixtures\TaxonomyDoctorExplicitDefaultTerm;
use Nvl\Taxonomy\Tests\Fixtures\TaxonomyDoctorForeignTerm;
use Nvl\Taxonomy\Tests\Fixtures\TaxonomyDoctorUnconfiguredTerm;

it('supports the public owner query and inspection workflow', function () {
    $categorized = Post::create(['title' => 'Categorized']);
    $uncategorized = Post::create(['title' => 'Uncategorized']);
    $parent = Category::create(['slug' => 'guides']);
    $child = Category::create(['slug' => 'laravel', 'parent_id' => $parent->id]);

    app(TaxonomyAssignments::class)->sync($categorized, 'tag', ['php', 'laravel'], SystemActor::Background);
    app(TaxonomyAssignments::class)->sync($categorized, 'category', [$child], SystemActor::Background);

    $tag = Tag::query()->where('slug', 'php')->firstOrFail();
    $freshOwner = $categorized->fresh();
    $pivotCount = $categorized->tags()->newPivotQuery()->count();
    $constrainedPivotSql = $categorized->tags()
        ->wherePivot('position', 0)
        ->wherePivotIn('position', [0, 1])
        ->wherePivotNull('updated_at')
        ->newPivotQuery()
        ->toSql();

    expect(Post::query()->withAnyTerms('tag', [], SystemActor::Background)->count())->toBe(0)
        ->and(Post::query()->withAllTerms('tag', [], SystemActor::Background)->count())->toBe(2)
        ->and(Post::query()->withoutTerms('tag', [], SystemActor::Background)->count())->toBe(2)
        ->and(Post::query()->withAnyTerms('tag', [$tag->id, 'missing'], SystemActor::Background)->sole()->is($categorized))->toBeTrue()
        ->and(Post::query()->withAllTerms('tag', ['php', 'laravel'], SystemActor::Background)->sole()->is($categorized))->toBeTrue()
        ->and(Post::query()->withoutTerms('tag', ['php'], SystemActor::Background)->sole()->is($uncategorized))->toBeTrue()
        ->and(Post::query()->inCategory($parent, SystemActor::Background)->sole()->is($categorized))->toBeTrue()
        ->and(Post::query()->inCategory($parent, SystemActor::Background, false)->count())->toBe(0)
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

    config()->set('nvl-taxonomy.limits.bulk_terms', 1);

    expect(fn () => Post::query()->withAnyTerms('tag', ['one', 'two'], SystemActor::Background)->get())
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => Post::query()->withAllTerms('tag', ['one', 'two'], SystemActor::Background)->get())
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => Post::query()->withoutTerms('tag', ['one', 'two'], SystemActor::Background)->get())
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

    $rebuildLock = Cache::lock('nvl:taxonomy:rebuild:'.app(TaxonomyBoundary::class)->key('taxonomy.terms', 'rebuild:category'), 3600);
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
    $pruneLock = Cache::lock('nvl:taxonomy:prune:'.app(TaxonomyBoundary::class)->key('taxonomy.terms', 'prune:tag'), 3600);
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
        config()->set('nvl-taxonomy.taxonomies', $configuration);

        expect(fn () => new TaxonomyRegistry)
            ->toThrow(InvalidArgumentException::class);
    }

    config()->set('nvl-taxonomy.taxonomies', []);
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

    expect(fn () => app(TaxonomyTerms::class)->create([
        'taxonomy' => 'tag', 'slug' => 'unknown-field',
        'translations' => ['en' => ['name' => 'Unknown']],
        'unexpected' => true,
    ], SystemActor::Background))->toThrow(InvalidArgumentException::class);
});

it('validates capability aliases without installing a host morph map', function () {
    $originalMorphMap = Relation::morphMap();
    $registry = new TaxonomyOwnerRegistry(
        app(TaxonomyBoundary::class),
        app(OwnerRegistry::class),
    );
    $registry->register('posts', Post::class);
    $registry->register('posts', Post::class);

    expect($registry->aliasFor(new Post))->toBe(Post::class)
        ->and($registry->all())->toBe([Post::class => Post::class])
        ->and($registry->unknownReferences(['posts', Post::class, 'unknown']))->toBe(['unknown'])
        ->and(fn () => $registry->register('Invalid Alias', Post::class))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('invalid-model', stdClass::class))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('posts', CustomKeyPost::class))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('other-posts', Post::class))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new TaxonomyOwnerRegistry(
            app(TaxonomyBoundary::class),
            app(OwnerRegistry::class),
        ))->register('posts', CustomKeyPost::class))
        ->toThrow(InvalidArgumentException::class);

    $separateRegistry = new TaxonomyOwnerRegistry(
        app(TaxonomyBoundary::class),
        app(OwnerRegistry::class),
    );
    $separateRegistry->register('new-posts', Post::class);
    expect($separateRegistry->aliasFor(new Post))->toBe(Post::class)
        ->and($separateRegistry->all())->toBe([Post::class => Post::class])
        ->and(Relation::morphMap())->toBe($originalMorphMap);

    config()->set('nvl-taxonomy.owners', 'invalid');
    app()->forgetInstance(TaxonomyOwnerRegistry::class);
    expect(fn () => app(TaxonomyOwnerRegistry::class))
        ->toThrow(InvalidArgumentException::class);

    config()->set('nvl-taxonomy.owners', [42]);
    app()->forgetInstance(TaxonomyOwnerRegistry::class);
    expect(fn () => app(TaxonomyOwnerRegistry::class))
        ->toThrow(InvalidArgumentException::class);

    config()->set('nvl-taxonomy.slugs.generator', stdClass::class);
    app()->forgetInstance(SlugGenerator::class);
    expect(fn () => app(SlugGenerator::class))
        ->toThrow(InvalidArgumentException::class);
});

it('reports missing consumer schema without attempting data inspection', function () {
    config()->set('nvl-taxonomy.tables', [
        'terms' => 'missing_terms',
        'i18n' => 'missing_terms_i18n',
        'termables' => 'missing_termables',
    ]);

    $checks = app(TaxonomyDoctor::class)->inspect();

    expect(collect($checks)->where('passed', false))->toHaveCount(9)
        ->and(collect($checks)->pluck('key')->all())->toContain(
            'schema.missing_terms',
            'indexes.missing_terms',
            'foreign_keys.missing_termables',
        );
});

it('compares registered vocabulary models by their effective source instead of nullable connection names', function (string $model, bool $compatible): void {
    $default = DB::getDefaultConnection();
    config()->set('database.connections.taxonomy_doctor_default', config('database.connections.'.$default));
    config()->set('database.connections.taxonomy_doctor_foreign', ['driver' => 'sqlite', 'database' => ':memory:']);
    $definitions = config('nvl-taxonomy.taxonomies');
    $definitions['doctor_source'] = ['model' => $model];
    config()->set('nvl-taxonomy.taxonomies', $definitions);

    try {
        DB::setDefaultConnection('taxonomy_doctor_default');
        $registry = new TaxonomyRegistry;
        app()->instance(TaxonomyRegistry::class, $registry);
        expect($registry->get('doctor_source')->model)->toBe($model)
            ->and((new Term)->getConnectionName())->toBeNull();
        $check = collect(app(TaxonomyDoctor::class)->inspect())->firstWhere('key', 'registry.model_connections');

        expect($check?->passed)->toBe($compatible);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('taxonomy_doctor_default');
        DB::purge('taxonomy_doctor_foreign');
    }
})->with([
    'explicit default source' => [TaxonomyDoctorExplicitDefaultTerm::class, true],
    'different actual source' => [TaxonomyDoctorForeignTerm::class, false],
    'unconfigured vocabulary source' => [TaxonomyDoctorUnconfiguredTerm::class, false],
]);
