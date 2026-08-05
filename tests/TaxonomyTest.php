<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nvl\Taxonomy\Actions\AttachTermsAction;
use Nvl\Taxonomy\Actions\CreateTermAction;
use Nvl\Taxonomy\Actions\DeleteTermAction;
use Nvl\Taxonomy\Actions\DetachTermsAction;
use Nvl\Taxonomy\Actions\MergeTermsAction;
use Nvl\Taxonomy\Actions\MoveTermAction;
use Nvl\Taxonomy\Actions\RebuildTreeAction;
use Nvl\Taxonomy\Actions\ResolveTermsAction;
use Nvl\Taxonomy\Actions\SyncTermAttachmentsAction;
use Nvl\Taxonomy\Actions\UpdateTermAction;
use Nvl\Taxonomy\Actions\ValidateTermMergeAction;
use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Taxonomy\Enums\DeleteTermStrategy;
use Nvl\Taxonomy\Enums\TermChangeOperation;
use Nvl\Taxonomy\Events\TermChanged;
use Nvl\Taxonomy\Exceptions\AmbiguousTermReferenceException;
use Nvl\Taxonomy\Exceptions\CircularHierarchyException;
use Nvl\Taxonomy\Exceptions\ClosedVocabularyException;
use Nvl\Taxonomy\Exceptions\DuplicateSiblingSlugException;
use Nvl\Taxonomy\Exceptions\MaximumDepthExceededException;
use Nvl\Taxonomy\Exceptions\StaleTermVersionException;
use Nvl\Taxonomy\Exceptions\UnsafeTermDeletionException;
use Nvl\Taxonomy\Models\Category;
use Nvl\Taxonomy\Models\Tag;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Providers\TaxonomyServiceProvider;
use Nvl\Taxonomy\Services\TaxonomyDoctor;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Taxonomy\Services\TaxonomyTree;
use Nvl\Taxonomy\Support\TaxonomyDefinition;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Taxonomy\Tests\Fixtures\CustomKeyPost;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Exceptions\InvalidTranslatableFieldException;
use Nvl\Translatable\Services\ContentLocale;

beforeEach(function () {
    CustomKeyPost::migrate();
    Post::migrate();
});

it('preserves nested taxonomy defaults around consumer definitions', function () {
    config()->set('taxonomy', [
        'taxonomies' => [
            'tag' => [
                'open' => true,
            ],
        ],
    ]);

    (new TaxonomyServiceProvider(app()))->register();

    expect(config('taxonomy.taxonomies.tag.sort'))->toBe('position')
        ->and(config('taxonomy.taxonomies.category.max_depth'))->toBe(3)
        ->and(config('taxonomy.table_names.terms_i18n'))->toBe('terms_i18n');
});

it('scopes terms by taxonomy correctly', function () {
    $tag = Tag::create(['name' => 'Laravel', 'slug' => 'laravel']);
    $cat = Category::create(['name' => 'Laravel', 'slug' => 'laravel']);

    expect(Tag::count())->toBe(1)
        ->and(Category::count())->toBe(1)
        ->and(Tag::first()->id)->not->toBe($cat->id);
});

it('keeps specialized term models inside their immutable vocabularies', function () {
    expect(fn () => Category::create([
        'taxonomy' => 'tag',
        'slug' => 'wrong-vocabulary',
    ]))->toThrow(InvalidArgumentException::class);

    $category = Category::create(['slug' => 'stable-category']);
    $category->taxonomy = 'tag';

    expect(fn () => $category->save())->toThrow(InvalidArgumentException::class)
        ->and(Category::query()->whereKey($category->id)->exists())->toBeTrue()
        ->and(Tag::query()->whereKey($category->id)->exists())->toBeFalse();
});

it('can sync open vocabularies like tags', function () {
    $post = Post::create(['title' => 'My Post']);

    app(SyncTermAttachmentsAction::class)->execute($post, 'tag', ['laravel', 'php']);

    expect($post->tags)->toHaveCount(2)
        ->and($post->tags->pluck('slug')->toArray())->toContain('laravel', 'php');
});

it('matches all-term scopes when multiple references identify the same term', function () {
    $post = Post::create(['title' => 'Aliased scope']);
    app(SyncTermAttachmentsAction::class)->execute($post, 'tag', ['laravel']);
    $term = $post->tags()->firstOrFail();

    expect(Post::query()->withAllTerms('tag', [$term->id, $term->slug])->count())->toBe(1)
        ->and(Post::query()->withAnyTerms('tag', [$term->id, $term->slug])->count())->toBe(1)
        ->and(Post::query()->withoutTerms('tag', [$term->id, $term->slug])->count())->toBe(0)
        ->and($post->hasTerm('tag', $term->slug))->toBeTrue();
});

it('throws on closed vocabularies when term does not exist', function () {
    $post = Post::create(['title' => 'My Post']);

    app(SyncTermAttachmentsAction::class)->execute($post, 'category', ['news']);
})->throws(ClosedVocabularyException::class);

it('detaches old terms on exclusive taxonomies', function () {
    $post = Post::create(['title' => 'My Post']);
    $cat1 = Category::create(['name' => 'News', 'slug' => 'news']);
    $cat2 = Category::create(['name' => 'Updates', 'slug' => 'updates']);

    app(SyncTermAttachmentsAction::class)->execute($post, 'category', [$cat1]);
    expect($post->categories)->toHaveCount(1);

    app(SyncTermAttachmentsAction::class)->execute($post, 'category', [$cat2]);
    $post->load('categories'); // refresh the relationship
    expect($post->categories)->toHaveCount(1)
        ->and($post->categories->first()->id)->toBe($cat2->id);
});

it('does not detach old terms on non-exclusive taxonomies', function () {
    $post = Post::create(['title' => 'My Post']);

    app(SyncTermAttachmentsAction::class)->execute($post, 'tag', ['laravel']);
    app(AttachTermsAction::class)->execute($post, 'tag', ['php']);

    $post->load('tags');
    expect($post->tags)->toHaveCount(2);
});

it('removes raw attachment rows when an owning model is deleted', function () {
    $post = Post::create(['title' => 'Disposable post']);
    app(SyncTermAttachmentsAction::class)->execute($post, 'tag', ['laravel']);

    $this->assertDatabaseCount(config('taxonomy.table_names.termables', 'termables'), 1);

    $post->delete();

    $this->assertDatabaseCount(config('taxonomy.table_names.termables', 'termables'), 0);
    expect(Tag::query()->where('slug', 'laravel')->exists())->toBeTrue();
});

it('generates a tree of categories', function () {
    $parent = Category::create(['name' => 'Parent', 'slug' => 'parent']);
    $child = Category::create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $parent->id]);

    $tree = Category::tree();

    expect($tree)->toHaveCount(1)
        ->and($tree->first()->id)->toBe($parent->id)
        ->and($tree->first()->children)->toHaveCount(1)
        ->and($tree->first()->children->first()->id)->toBe($child->id);
});

it('queries category subtrees with qualified relationship columns', function () {
    $post = Post::create(['title' => 'Categorized post']);
    $parent = Category::create(['slug' => 'section']);
    $child = Category::create(['slug' => 'subsection', 'parent_id' => $parent->id]);
    app(SyncTermAttachmentsAction::class)->execute($post, 'category', [$child]);

    expect(Post::query()->inCategory($parent)->count())->toBe(1);
});

it('creates dedicated term translations with deterministic field fallback', function () {
    config()->set('translatable.locales', ['en', 'bg']);
    config()->set('translatable.fallback_locales', ['en']);

    $term = app(CreateTermAction::class)->execute(
        MutateTermPayload::from([
            'taxonomy' => 'category',
            'slug' => 'electronics',
            'translations' => [
                'en' => [
                    'name' => 'Electronics',
                    'description' => 'Devices and equipment',
                ],
                'bg' => [
                    'name' => 'Електроника',
                    'description' => null,
                ],
            ],
        ]),
    );

    expect($term->translations)->toHaveCount(2)
        ->and($term->displayName('bg'))->toBe('Електроника')
        ->and($term->displayDescription('bg'))->toBe('Devices and equipment')
        ->and($term->slug)->toBe('electronics');
});

it('patches and replaces translated term copy through explicit update modes', function () {
    config()->set('translatable.locales', ['en', 'bg']);
    config()->set('translatable.fallback_locales', ['en']);

    $term = app(CreateTermAction::class)->execute(MutateTermPayload::from([
        'taxonomy' => 'category',
        'slug' => 'localized-update',
        'translations' => [
            'en' => ['name' => 'English'],
            'bg' => ['name' => 'Български'],
        ],
    ]));

    $patched = app(UpdateTermAction::class)->execute(
        $term,
        MutateTermPayload::from([
            'taxonomy' => 'category',
            'slug' => 'localized-update',
            'translations' => [
                'en' => [
                    'name' => 'Updated English',
                    'description' => 'Patched description',
                ],
            ],
            'expectedRevision' => $term->revision,
        ]),
    );

    expect($patched->translations->pluck('locale')->all())
        ->toEqualCanonicalizing(['en', 'bg'])
        ->and($patched->displayName('bg'))->toBe('Български');

    $replaced = app(UpdateTermAction::class)->execute(
        $patched,
        MutateTermPayload::from([
            'taxonomy' => 'category',
            'slug' => 'localized-update',
            'translations' => [
                'en' => ['name' => 'English only'],
            ],
            'expectedRevision' => $patched->revision,
        ]),
        TranslationSyncMode::Replace,
    );

    expect($replaced->translations->pluck('locale')->all())->toBe(['en'])
        ->and($replaced->displayName('en'))->toBe('English only');
});

it('stores localized copy only in dedicated translation rows', function () {
    config()->set('translatable.locales', ['en', 'bg']);
    config()->set('translatable.fallback_locales', ['en']);

    $term = app(CreateTermAction::class)->execute(
        MutateTermPayload::from([
            'taxonomy' => 'category',
            'slug' => 'deterministic',
            'translations' => [
                'bg' => [
                    'name' => 'Български',
                    'description' => 'Българско описание',
                ],
                'en' => [
                    'name' => 'English',
                    'description' => 'English description',
                ],
            ],
        ]),
    );

    expect($term->getAttributes())->not->toHaveKeys(['name', 'description'])
        ->and($term->displayName('en'))->toBe('English')
        ->and($term->displayDescription('en'))->toBe('English description');
});

it('rolls back term creation on the configured taxonomy connection when translations fail', function () {
    $connection = 'taxonomy_secondary';
    $database = tempnam(sys_get_temp_dir(), 'nvl-taxonomy-');

    if ($database === false) {
        throw new RuntimeException('Unable to create the taxonomy test database.');
    }

    config()->set("database.connections.{$connection}", [
        'driver' => 'sqlite',
        'database' => $database,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('taxonomy.storage.connection', $connection);
    config()->set('translatable.locales', ['en']);

    try {
        $termsMigration = require __DIR__.'/../database/migrations/2026_01_01_000000_create_terms_table.php';
        $translationsMigration = require __DIR__.'/../database/migrations/2026_07_26_083129_create_terms_i18n_table.php';
        $termsMigration->up();
        $translationsMigration->up();

        expect(fn () => app(CreateTermAction::class)->execute(
            MutateTermPayload::from([
                'taxonomy' => 'category',
                'slug' => 'invalid-copy',
                'translations' => [
                    'en' => [
                        'name' => 'Invalid copy',
                        'undeclared' => 'must fail',
                    ],
                ],
            ]),
        ))->toThrow(InvalidTranslatableFieldException::class)
            ->and(Term::query()->count())->toBe(0);
    } finally {
        DB::purge($connection);
        unlink($database);
    }
});

it('uses the request scoped content locale when creating open vocabulary terms', function () {
    config()->set('translatable.locales', ['en', 'bg']);
    app(ContentLocale::class)->set('bg');

    $post = Post::create(['title' => 'Localized post']);
    app(SyncTermAttachmentsAction::class)->execute($post, 'tag', ['Новини']);

    $tag = $post->tags()->firstOrFail();

    expect($tag->hasTranslation('bg'))->toBeTrue()
        ->and($tag->displayName('bg'))->toBe('Новини');
});

it('uses UUID keys, prevents cycles and stale writes, and exposes a healthy doctor', function () {
    config()->set('translatable.locales', ['en']);
    $create = app(CreateTermAction::class);
    $parent = $create->execute(MutateTermPayload::from([
        'taxonomy' => 'category',
        'slug' => 'parent',
        'translations' => ['en' => ['name' => 'Parent']],
    ]));
    $child = $create->execute(MutateTermPayload::from([
        'taxonomy' => 'category',
        'slug' => 'child',
        'parentId' => $parent->id,
        'translations' => ['en' => ['name' => 'Child']],
    ]));
    $revision = $parent->revision;

    expect($parent->id)->toBeUuid()
        ->and($parent->parent_id)->toBeNull()
        ->and(fn () => app(MoveTermAction::class)->execute(
            $parent,
            $child->id,
            0,
            $revision,
        ))->toThrow(CircularHierarchyException::class);

    $updated = app(UpdateTermAction::class)->execute($parent, MutateTermPayload::from([
        'taxonomy' => 'category',
        'slug' => 'parent-updated',
        'translations' => ['en' => ['name' => 'Updated']],
        'expectedRevision' => $revision,
    ]));

    expect(fn () => app(UpdateTermAction::class)->execute($parent, MutateTermPayload::from([
        'taxonomy' => 'category',
        'slug' => 'stale',
        'translations' => ['en' => ['name' => 'Stale']],
        'expectedRevision' => $revision,
    ])))->toThrow(StaleTermVersionException::class)
        ->and($updated->revision)->toBeGreaterThan($revision)
        ->and(collect(app(TaxonomyDoctor::class)->inspect())
            ->reject(static fn ($check): bool => $check->passed)
            ->mapWithKeys(static fn ($check): array => [$check->key => $check->message])
            ->all())->toBe([]);
});

it('synchronizes registered owner attachments and enforces deletion strategies', function () {
    config()->set('translatable.locales', ['en']);
    $post = Post::create(['title' => 'Attachment owner']);
    $term = app(CreateTermAction::class)->execute(MutateTermPayload::from([
        'taxonomy' => 'tag',
        'slug' => 'laravel',
        'translations' => ['en' => ['name' => 'Laravel']],
    ]));

    app(SyncTermAttachmentsAction::class)->execute($post, 'tag', [$term]);

    expect($post->fresh()?->tags)->toHaveCount(1)
        ->and($term->entries(Post::class)->get())->toHaveCount(1)
        ->and($term->entries(Post::class)->first()?->getKey())->toBe($post->getKey())
        ->and(fn () => app(DeleteTermAction::class)->execute(
            $term,
            $term->revision,
        ))->toThrow(UnsafeTermDeletionException::class)
        ->and(app(DeleteTermAction::class)->execute(
            $term,
            $term->revision,
            DeleteTermStrategy::Detach,
        ))->toBeTrue();
});

it('isolates attachment mutations by owner and vocabulary', function () {
    $firstPost = Post::create(['title' => 'First owner']);
    $secondPost = Post::create(['title' => 'Second owner']);
    $category = Category::create(['slug' => 'isolated-category']);

    app(SyncTermAttachmentsAction::class)->execute($firstPost, 'tag', ['shared-tag']);
    app(SyncTermAttachmentsAction::class)->execute($secondPost, 'tag', ['shared-tag']);
    app(SyncTermAttachmentsAction::class)->execute($firstPost, 'category', [$category]);
    app(DetachTermsAction::class)->execute($firstPost, 'tag');

    expect($firstPost->fresh()?->tags)->toHaveCount(0)
        ->and($firstPost->fresh()?->categories)->toHaveCount(1)
        ->and($secondPost->fresh()?->tags)->toHaveCount(1)
        ->and(DB::table('termables')->count())->toBe(2);
});

it('reparents children and cascades complete subtrees through explicit delete strategies', function () {
    $destination = Category::create(['slug' => 'destination']);
    $reparentedRoot = Category::create(['slug' => 'reparented-root']);
    $reparentedChild = Category::create([
        'slug' => 'reparented-child',
        'parent_id' => $reparentedRoot->id,
    ]);

    expect(app(DeleteTermAction::class)->execute(
        $reparentedRoot,
        $reparentedRoot->revision,
        DeleteTermStrategy::Reparent,
        $destination->id,
    ))->toBeTrue()
        ->and($reparentedChild->refresh()->parent_id)->toBe($destination->id);

    $cascadeRoot = Category::create(['slug' => 'cascade-root']);
    $cascadeChild = Category::create([
        'slug' => 'cascade-child',
        'parent_id' => $cascadeRoot->id,
    ]);
    $cascadeLeaf = Category::create([
        'slug' => 'cascade-leaf',
        'parent_id' => $cascadeChild->id,
    ]);
    $post = Post::create(['title' => 'Cascade owner']);
    app(SyncTermAttachmentsAction::class)->execute($post, 'category', [$cascadeLeaf]);

    expect(app(DeleteTermAction::class)->execute(
        $cascadeRoot,
        $cascadeRoot->revision,
        DeleteTermStrategy::Cascade,
    ))->toBeTrue()
        ->and(Category::query()->whereKey([
            $cascadeRoot->id,
            $cascadeChild->id,
            $cascadeLeaf->id,
        ])->count())->toBe(0)
        ->and($post->fresh()?->categories)->toHaveCount(0)
        ->and($destination->fresh())->not->toBeNull();
});

it('stores stable owner aliases and UUID-backed attachment rows', function () {
    $post = Post::create(['title' => 'Stable owner']);
    app(SyncTermAttachmentsAction::class)->execute($post, 'tag', ['laravel']);

    $row = DB::table(config('taxonomy.table_names.termables', 'termables'))->first();

    expect($row)->not->toBeNull()
        ->and($row->id)->toBeUuid()
        ->and($row->termable_type)->toBe('posts')
        ->and($post->getMorphClass())->toBe('posts');
});

it('resolves raw attachment relations through custom owner primary keys', function () {
    $post = CustomKeyPost::create([
        'post_key' => 'custom-post-1',
        'title' => 'Custom key owner',
    ]);

    app(SyncTermAttachmentsAction::class)->execute($post, 'tag', ['laravel']);

    expect($post->tags()->sole()->slug)->toBe('laravel')
        ->and($post->termables()->sole()->termable_id)->toBe('custom-post-1');
});

it('uses the UUID pivot model for relation-level attachment compatibility', function () {
    $post = Post::create(['title' => 'Relation owner']);
    $tag = Tag::create(['slug' => 'relation-tag']);
    $post->tags()->attach($tag, ['taxonomy' => 'tag', 'position' => 0]);

    $row = DB::table(config('taxonomy.table_names.termables', 'termables'))->first();

    expect($row?->id)->toBeUuid()
        ->and($row?->termable_type)->toBe('posts');
});

it('rejects multiple exclusive terms without replacing the current attachment', function () {
    $post = Post::create(['title' => 'Exclusive category']);
    $first = Category::create(['slug' => 'first']);
    $second = Category::create(['slug' => 'second']);

    app(SyncTermAttachmentsAction::class)->execute($post, 'category', [$first]);

    expect(fn () => app(SyncTermAttachmentsAction::class)->execute(
        $post,
        'category',
        [$first, $second],
    ))->toThrow(InvalidArgumentException::class)
        ->and($post->fresh()?->categories()->pluck('terms.id')->all())->toBe([$first->id]);
});

it('requires optimistic revisions for every update', function () {
    config()->set('translatable.locales', ['en']);
    $term = app(CreateTermAction::class)->execute(MutateTermPayload::from([
        'taxonomy' => 'tag',
        'slug' => 'revisioned',
        'translations' => ['en' => ['name' => 'Revisioned']],
    ]));

    app(UpdateTermAction::class)->execute($term, MutateTermPayload::from([
        'taxonomy' => 'tag',
        'slug' => 'revisioned',
        'translations' => ['en' => ['name' => 'Changed']],
    ]));
})->throws(StaleTermVersionException::class);

it('validates the deepest descendant when moving a subtree', function () {
    $root = Category::create(['slug' => 'root']);
    $middle = Category::create(['slug' => 'middle', 'parent_id' => $root->id]);
    $leaf = Category::create(['slug' => 'leaf', 'parent_id' => $middle->id]);
    $otherRoot = Category::create(['slug' => 'other']);

    expect($leaf->ancestors()->pluck('id')->all())->toBe([$middle->id, $root->id])
        ->and(fn () => app(UpdateTermAction::class)->execute(
            $middle,
            new MutateTermPayload(
                taxonomy: 'category',
                slug: 'middle',
                translations: ['en' => ['name' => 'Middle']],
                parentId: $otherRoot->id,
                expectedRevision: $middle->revision,
            ),
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(MoveTermAction::class)->execute(
            $root,
            $otherRoot->id,
            0,
            $root->revision,
        ))->toThrow(MaximumDepthExceededException::class);
});

it('requires UUID references when hierarchical slugs are ambiguous', function () {
    $firstRoot = Category::create(['slug' => 'first-root']);
    $secondRoot = Category::create(['slug' => 'second-root']);
    Category::create(['slug' => 'shared', 'parent_id' => $firstRoot->id]);
    Category::create(['slug' => 'shared', 'parent_id' => $secondRoot->id]);

    app(ResolveTermsAction::class)->execute('category', ['shared']);
})->throws(AmbiguousTermReferenceException::class);

it('normalizes root positions independently for each taxonomy', function () {
    $tag = Tag::create(['slug' => 'tag-root', 'position' => 8]);
    $category = Category::create(['slug' => 'category-root', 'position' => 9]);

    expect(app(RebuildTreeAction::class)->execute(dryRun: true))->toBe(2)
        ->and($tag->refresh()->position)->toBe(8)
        ->and($category->refresh()->position)->toBe(9)
        ->and(app(RebuildTreeAction::class)->execute())->toBe(2)
        ->and($tag->refresh()->position)->toBe(0)
        ->and($category->refresh()->position)->toBe(0);
});

it('protects closed vocabulary terms from orphan pruning by default', function () {
    $category = Category::create(['slug' => 'canonical']);
    $tag = Tag::create(['slug' => 'temporary']);

    $this->artisan('nvl:taxonomy:prune', ['--force' => true])
        ->assertSuccessful();

    expect($category->fresh())->not->toBeNull()
        ->and($tag->fresh())->toBeNull();
});

it('enforces metadata depth and canonical slug contracts', function () {
    config()->set('translatable.locales', ['en']);
    config()->set('taxonomy.limits.metadata_depth', 2);

    expect(fn () => app(CreateTermAction::class)->execute(MutateTermPayload::from([
        'taxonomy' => 'tag',
        'slug' => 'Bad Slug',
        'translations' => ['en' => ['name' => 'Bad']],
    ])))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(CreateTermAction::class)->execute(MutateTermPayload::from([
            'taxonomy' => 'tag',
            'slug' => 'deep',
            'meta' => ['one' => ['two' => ['three' => true]]],
            'translations' => ['en' => ['name' => 'Deep']],
        ])))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(CreateTermAction::class)->execute(new MutateTermPayload(
            taxonomy: 'tag',
            slug: 'untranslated',
            translations: [],
        )))->toThrow(InvalidArgumentException::class);
});

it('checks bulk limits before creating open-vocabulary terms', function () {
    config()->set('taxonomy.limits.bulk_terms', 1);
    $post = Post::create(['title' => 'Limited']);

    expect(fn () => app(SyncTermAttachmentsAction::class)->execute(
        $post,
        'tag',
        ['one', 'two'],
    ))->toThrow(InvalidArgumentException::class)
        ->and(Tag::query()->count())->toBe(0);
});

it('preserves mixed reference order and rejects sibling collisions on moves', function () {
    $post = Post::create(['title' => 'Ordered terms']);
    $existing = Tag::create(['slug' => 'existing']);
    app(SyncTermAttachmentsAction::class)->execute(
        $post,
        'tag',
        ['created-first', $existing],
    );

    $firstRoot = Category::create(['slug' => 'first-root']);
    $secondRoot = Category::create(['slug' => 'second-root']);
    Category::create(['slug' => 'duplicate', 'parent_id' => $firstRoot->id]);
    $moving = Category::create(['slug' => 'duplicate', 'parent_id' => $secondRoot->id]);

    expect($post->tags()->pluck('slug')->all())->toBe(['created-first', 'existing'])
        ->and(fn () => app(MoveTermAction::class)->execute(
            $moving,
            $firstRoot->id,
            0,
            $moving->revision,
        ))->toThrow(DuplicateSiblingSlugException::class);
});

it('supports generic trees, no-op unknown detaches, and merge invariants', function () {
    $post = Post::create(['title' => 'Merge owner']);
    $source = Category::create(['slug' => 'source']);
    $destination = Category::create(['slug' => 'destination']);
    $child = Category::create(['slug' => 'child', 'parent_id' => $source->id]);
    app(SyncTermAttachmentsAction::class)->execute($post, 'category', [$source]);

    expect(app(DetachTermsAction::class)->execute($post, 'category', ['unknown']))->toBe(0);

    $merged = app(MergeTermsAction::class)->execute(
        $source,
        $destination,
        $source->revision,
        $destination->revision,
    );
    $tree = app(TaxonomyTree::class)->for('category');

    expect($post->fresh()?->categories()->first()?->id)->toBe($destination->id)
        ->and($child->refresh()->parent_id)->toBe($destination->id)
        ->and($merged)->toBeInstanceOf(Category::class)
        ->and($merged->revision)->toBeGreaterThan($destination->revision)
        ->and($tree->pluck('id')->all())->toBe([$destination->id]);
});

it('loads localized trees without per-node translation queries', function () {
    config()->set('translatable.locales', ['en']);
    $create = app(CreateTermAction::class);
    $root = $create->execute(MutateTermPayload::from([
        'taxonomy' => 'category',
        'slug' => 'query-root',
        'translations' => ['en' => ['name' => 'Query root']],
    ]));
    $child = $create->execute(MutateTermPayload::from([
        'taxonomy' => 'category',
        'slug' => 'query-child',
        'parentId' => $root->id,
        'translations' => ['en' => ['name' => 'Query child']],
    ]));
    $create->execute(MutateTermPayload::from([
        'taxonomy' => 'category',
        'slug' => 'query-leaf',
        'parentId' => $child->id,
        'translations' => ['en' => ['name' => 'Query leaf']],
    ]));

    DB::flushQueryLog();
    DB::enableQueryLog();

    $tree = app(TaxonomyTree::class)->for('category', 'en');
    $names = [
        $tree->sole()->displayName('en'),
        $tree->sole()->children->sole()->displayName('en'),
        $tree->sole()->children->sole()->children->sole()->displayName('en'),
    ];
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($names)->toBe(['Query root', 'Query child', 'Query leaf'])
        ->and($queryCount)->toBeLessThanOrEqual(2);
});

it('discards term events when an outer transaction rolls back', function () {
    config()->set('translatable.locales', ['en']);
    Event::fake([TermChanged::class]);
    $connection = DB::connection((new Term)->getConnectionName());
    $initialTransactionLevel = $connection->transactionLevel();
    $connection->beginTransaction();

    try {
        app(CreateTermAction::class)->execute(MutateTermPayload::from([
            'taxonomy' => 'tag',
            'slug' => 'rolled-back-event',
            'translations' => ['en' => ['name' => 'Rolled back']],
        ]));

        Event::assertNotDispatched(TermChanged::class);
        $connection->rollBack();
    } finally {
        if ($connection->transactionLevel() > $initialTransactionLevel) {
            $connection->rollBack();
        }
    }

    Event::assertNotDispatched(TermChanged::class);
    expect(Tag::query()->where('slug', 'rolled-back-event')->exists())->toBeFalse();
});

it('emits one committed event with the resulting term revision', function () {
    config()->set('translatable.locales', ['en']);
    Event::fake([TermChanged::class]);

    $term = app(CreateTermAction::class)->execute(MutateTermPayload::from([
        'taxonomy' => 'tag',
        'slug' => 'committed-event',
        'translations' => ['en' => ['name' => 'Committed']],
    ]));

    Event::assertDispatched(
        TermChanged::class,
        static fn (TermChanged $event): bool => $event->termId === $term->id
            && $event->taxonomy === 'tag'
            && $event->operation === TermChangeOperation::Created
            && $event->revision === $term->revision,
    );
    Event::assertDispatchedTimes(TermChanged::class, 1);
});

it('does not swallow non-unique database failures while creating open terms', function () {
    $translationTable = config('taxonomy.table_names.terms_i18n', 'terms_i18n');
    config()->set(
        'taxonomy.table_names.terms_i18n',
        'taxonomy_translation_failure',
    );
    $post = Post::create(['title' => 'Failed open term']);

    try {
        expect(fn () => app(SyncTermAttachmentsAction::class)->execute(
            $post,
            'tag',
            ['must-rollback'],
        ))->toThrow(QueryException::class)
            ->and(Tag::query()->where('slug', 'must-rollback')->exists())->toBeFalse()
            ->and(DB::table('termables')->count())->toBe(0);
    } finally {
        config()->set('taxonomy.table_names.terms_i18n', $translationTable);
    }
});

it('rejects stale term and owner instances before writing attachments', function () {
    $post = Post::create(['title' => 'Live owner']);
    $staleTerm = Tag::create(['slug' => 'stale-term']);
    Tag::query()->whereKey($staleTerm->id)->delete();

    expect(fn () => app(SyncTermAttachmentsAction::class)->execute(
        $post,
        'tag',
        [$staleTerm],
    ))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('termables')->count())->toBe(0);

    $staleOwner = Post::create(['title' => 'Stale owner']);
    Post::query()->whereKey($staleOwner->getKey())->delete();

    expect(fn () => app(SyncTermAttachmentsAction::class)->execute(
        $staleOwner,
        'tag',
        ['must-not-exist'],
    ))->toThrow(InvalidArgumentException::class)
        ->and(Tag::query()->where('slug', 'must-not-exist')->exists())->toBeFalse();
});

it('requires exact concrete owner registration for stable morph aliases', function () {
    $derivedOwner = new class extends Post {};

    expect(fn () => app(TaxonomyOwnerRegistry::class)->aliasFor($derivedOwner))
        ->toThrow(InvalidArgumentException::class);
});

it('validates programmatic taxonomy definitions at the registry boundary', function () {
    $definition = new TaxonomyDefinition(
        taxonomy: 'Invalid Alias',
        model: Term::class,
        hierarchical: false,
        exclusive: false,
        open: false,
        maxDepth: 0,
        sort: 'position',
        allowedOwners: [],
        metadataRules: [],
    );

    expect(fn () => app(TaxonomyRegistry::class)->register($definition))
        ->toThrow(InvalidArgumentException::class);
});

it('reserves UUID syntax for identifiers and bounds translation descriptions', function () {
    config()->set('translatable.locales', ['en']);
    config()->set('taxonomy.limits.description_chars', 3);

    expect(fn () => app(CreateTermAction::class)->execute(MutateTermPayload::from([
        'taxonomy' => 'tag',
        'slug' => 'c59c7d68-2b00-45b5-b926-66ba5157e830',
        'translations' => ['en' => ['name' => 'Reserved']],
    ])))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(CreateTermAction::class)->execute(new MutateTermPayload(
            taxonomy: 'tag',
            slug: 'bounded-description',
            translations: ['en' => ['name' => 'Bounded', 'description' => 'four']],
        )))->toThrow(InvalidArgumentException::class);
});

it('validates multi-child merge hierarchy in a constant number of term reads', function () {
    $source = Category::create(['slug' => 'source-root']);
    $destination = Category::create(['slug' => 'destination-root']);

    foreach (range(1, 12) as $index) {
        Category::create([
            'slug' => "child-{$index}",
            'parent_id' => $source->id,
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(ValidateTermMergeAction::class)->execute(
        $source,
        $destination,
        $source->revision,
        $destination->revision,
    );

    $termReads = collect(DB::getQueryLog())
        ->filter(static fn (array $query): bool => str_contains(
            strtolower($query['query']),
            'from "terms"',
        ))
        ->count();
    DB::disableQueryLog();

    expect($termReads)->toBe(4);
});

it('rejects negative move positions and invalid doctor formats', function () {
    $term = Category::create(['slug' => 'positioned']);

    expect(fn () => app(MoveTermAction::class)->execute(
        $term,
        null,
        -1,
        $term->revision,
    ))->toThrow(InvalidArgumentException::class);

    $this->artisan('nvl:taxonomy:doctor', ['--format' => 'yaml'])
        ->expectsOutput('The taxonomy doctor format must be [text] or [json].')
        ->assertFailed();
});

it('preserves configured term subclasses across hierarchy relations', function () {
    $parent = Category::create(['slug' => 'typed-parent']);
    $child = Category::create(['slug' => 'typed-child', 'parent_id' => $parent->id]);
    $descendant = $parent->descendants()->first();
    $moved = app(MoveTermAction::class)->execute(
        $child,
        null,
        0,
        $child->revision,
    );

    expect($child->parent)->toBeInstanceOf(Category::class)
        ->and($descendant)->toBeInstanceOf(Category::class)
        ->and($moved)->toBeInstanceOf(Category::class);
});

it('keeps move merge delete and attachments on the configured connection', function () {
    $connection = 'taxonomy_mutations';
    $database = tempnam(sys_get_temp_dir(), 'nvl-taxonomy-mutations-');

    if ($database === false) {
        throw new RuntimeException('Unable to create the taxonomy mutation database.');
    }

    config()->set("database.connections.{$connection}", [
        'driver' => 'sqlite',
        'database' => $database,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('taxonomy.storage.connection', $connection);
    config()->set('translatable.locales', ['en']);

    try {
        foreach ([
            '2026_01_01_000000_create_terms_table.php',
            '2026_01_01_000001_create_termables_table.php',
            '2026_07_26_083129_create_terms_i18n_table.php',
        ] as $migration) {
            (require __DIR__.'/../database/migrations/'.$migration)->up();
        }

        $create = app(CreateTermAction::class);
        $firstRoot = $create->execute(MutateTermPayload::from([
            'taxonomy' => 'category',
            'slug' => 'first-root',
            'translations' => ['en' => ['name' => 'First root']],
        ]));
        $secondRoot = $create->execute(MutateTermPayload::from([
            'taxonomy' => 'category',
            'slug' => 'second-root',
            'translations' => ['en' => ['name' => 'Second root']],
        ]));
        $child = $create->execute(MutateTermPayload::from([
            'taxonomy' => 'category',
            'slug' => 'child',
            'parentId' => $firstRoot->id,
            'translations' => ['en' => ['name' => 'Child']],
        ]));
        $moved = app(MoveTermAction::class)->execute(
            $child,
            $secondRoot->id,
            0,
            $child->revision,
        );
        $source = $create->execute(MutateTermPayload::from([
            'taxonomy' => 'tag',
            'slug' => 'source',
            'translations' => ['en' => ['name' => 'Source']],
        ]));
        $destination = $create->execute(MutateTermPayload::from([
            'taxonomy' => 'tag',
            'slug' => 'destination',
            'translations' => ['en' => ['name' => 'Destination']],
        ]));
        $post = Post::create(['title' => 'Cross-connection owner']);
        app(SyncTermAttachmentsAction::class)->execute($post, 'tag', [$source]);
        app(MergeTermsAction::class)->execute(
            $source,
            $destination,
            $source->revision,
            $destination->revision,
        );
        app(DeleteTermAction::class)->execute(
            $moved,
            $moved->revision,
            DeleteTermStrategy::Restrict,
        );

        expect($moved->getConnectionName())->toBe($connection)
            ->and(DB::connection($connection)->table('terms')->where('id', $child->id)->exists())->toBeFalse()
            ->and(DB::connection($connection)->table('termables')->value('term_id'))->toBe($destination->id)
            ->and(DB::connection()->table('terms')->count())->toBe(0);
    } finally {
        config()->set('taxonomy.storage.connection', null);
        DB::purge($connection);
        unlink($database);
    }
});
