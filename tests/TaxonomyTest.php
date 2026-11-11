<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
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
use Nvl\Taxonomy\Services\TaxonomyAssignments;
use Nvl\Taxonomy\Services\TaxonomyDoctor;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Taxonomy\Services\TaxonomyReader;
use Nvl\Taxonomy\Services\TaxonomyTerms;
use Nvl\Taxonomy\Services\TaxonomyTree;
use Nvl\Taxonomy\Services\TermHierarchy;
use Nvl\Taxonomy\Support\TaxonomyDefinition;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Taxonomy\Tests\Fixtures\BatchArchivedPost;
use Nvl\Taxonomy\Tests\Fixtures\CustomKeyPost;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Exceptions\InvalidTranslatableFieldException;
use Nvl\Translatable\Services\ContentLocale;

it('preserves nested taxonomy defaults around consumer definitions', function () {
    config()->set('nvl-taxonomy', [
        'taxonomies' => [
            'tag' => [
                'open' => true,
            ],
        ],
    ]);

    (new TaxonomyServiceProvider(app()))->register();

    expect(config('nvl-taxonomy.taxonomies.tag.sort'))->toBe('position')
        ->and(config('nvl-taxonomy.taxonomies.category.max_depth'))->toBe(3)
        ->and(TaxonomyTables::get(TaxonomyTables::I18n))->toBe('nvl_taxonomy_i18n');
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

    app(TaxonomyAssignments::class)->sync($post, 'tag', ['laravel', 'php'], SystemActor::Background);

    expect($post->tags)->toHaveCount(2)
        ->and($post->tags->pluck('slug')->toArray())->toContain('laravel', 'php');
});

it('matches all-term scopes when multiple references identify the same term', function () {
    $post = Post::create(['title' => 'Aliased scope']);
    app(TaxonomyAssignments::class)->sync($post, 'tag', ['laravel'], SystemActor::Background);
    $term = $post->tags()->firstOrFail();

    expect(Post::query()->withAllTerms('tag', [$term->id, $term->slug], SystemActor::Background)->count())->toBe(1)
        ->and(Post::query()->withAnyTerms('tag', [$term->id, $term->slug], SystemActor::Background)->count())->toBe(1)
        ->and(Post::query()->withoutTerms('tag', [$term->id, $term->slug], SystemActor::Background)->count())->toBe(0)
        ->and($post->hasTerm('tag', $term->slug))->toBeTrue();
});

it('throws on closed vocabularies when term does not exist', function () {
    $post = Post::create(['title' => 'My Post']);

    app(TaxonomyAssignments::class)->sync($post, 'category', ['news'], SystemActor::Background);
})->throws(ClosedVocabularyException::class);

it('detaches old terms on exclusive taxonomies', function () {
    $post = Post::create(['title' => 'My Post']);
    $cat1 = Category::create(['name' => 'News', 'slug' => 'news']);
    $cat2 = Category::create(['name' => 'Updates', 'slug' => 'updates']);

    app(TaxonomyAssignments::class)->sync($post, 'category', [$cat1], SystemActor::Background);
    expect($post->categories)->toHaveCount(1);

    app(TaxonomyAssignments::class)->sync($post, 'category', [$cat2], SystemActor::Background);
    $post->load('categories'); // refresh the relationship
    expect($post->categories)->toHaveCount(1)
        ->and($post->categories->first()->id)->toBe($cat2->id);
});

it('does not detach old terms on non-exclusive taxonomies', function () {
    $post = Post::create(['title' => 'My Post']);

    app(TaxonomyAssignments::class)->sync($post, 'tag', ['laravel'], SystemActor::Background);
    app(TaxonomyAssignments::class)->attach($post, 'tag', ['php'], SystemActor::Background);

    $post->load('tags');
    expect($post->tags)->toHaveCount(2);
});

it('removes raw attachment rows when an owning model is deleted', function () {
    $post = Post::create(['title' => 'Disposable post']);
    app(TaxonomyAssignments::class)->sync($post, 'tag', ['laravel'], SystemActor::Background);

    $this->assertDatabaseCount(TaxonomyTables::get(TaxonomyTables::Termables), 1);

    $post->delete();

    $this->assertDatabaseCount(TaxonomyTables::get(TaxonomyTables::Termables), 0);
    expect(Tag::query()->where('slug', 'laravel')->exists())->toBeTrue();
});

it('removes attachment rows when an already soft-deleted owner is force deleted', function () {
    // MySQL DDL commits the wrapping test transaction, so the column is added and removed outside it.
    $commitsDdl = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $table = (new Post)->getTable();
    $addsColumn = ! Schema::hasColumn($table, 'archived_at');
    if ($addsColumn) {
        if ($commitsDdl) {
            DB::rollBack();
        }
        Schema::table($table, static fn (Blueprint $table) => $table->softDeletes('archived_at'));
        if ($commitsDdl) {
            DB::beginTransaction();
        }
    }

    try {
        app(TaxonomyOwnerRegistry::class)->register('archived_posts', BatchArchivedPost::class);
        $post = BatchArchivedPost::create(['title' => 'Trashed post']);
        app(TaxonomyAssignments::class)->sync($post, 'tag', ['laravel'], SystemActor::Background);

        $post->delete();

        expect($post->trashed())->toBeTrue();
        $this->assertDatabaseCount(TaxonomyTables::get(TaxonomyTables::Termables), 1);
        expect(fn () => app(TaxonomyAssignments::class)->sync($post, 'tag', ['php'], SystemActor::Background))
            ->toThrow(InvalidArgumentException::class, 'The canonical taxonomy owner is unavailable.');

        $post->forceDelete();

        $this->assertDatabaseCount(TaxonomyTables::get(TaxonomyTables::Termables), 0);
        expect(BatchArchivedPost::withTrashed()->whereKey($post->getKey())->exists())->toBeFalse();
    } finally {
        if ($addsColumn && $commitsDdl) {
            DB::rollBack();
            Schema::table($table, static fn (Blueprint $table) => $table->dropColumn('archived_at'));
            DB::beginTransaction();
        }
    }
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
    app(TaxonomyAssignments::class)->sync($post, 'category', [$child], SystemActor::Background);

    expect(Post::query()->inCategory($parent, SystemActor::Background)->count())->toBe(1);
});

it('creates dedicated term translations with deterministic field fallback', function () {
    config()->set('nvl-translatable.locales', ['en', 'bg']);
    config()->set('nvl-translatable.fallback_locales', ['en']);

    $term = app(TaxonomyTerms::class)->create([
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
    ], SystemActor::Background);

    expect($term->translations)->toHaveCount(2)
        ->and($term->displayName('bg'))->toBe('Електроника')
        ->and($term->displayDescription('bg'))->toBe('Devices and equipment')
        ->and($term->slug)->toBe('electronics');
});

it('patches and replaces translated term copy through explicit update modes', function () {
    config()->set('nvl-translatable.locales', ['en', 'bg']);
    config()->set('nvl-translatable.fallback_locales', ['en']);

    $term = app(TaxonomyTerms::class)->create([
        'taxonomy' => 'category',
        'slug' => 'localized-update',
        'translations' => [
            'en' => ['name' => 'English'],
            'bg' => ['name' => 'Български'],
        ],
    ], SystemActor::Background);

    $patched = app(TaxonomyTerms::class)->update($term, [
        'taxonomy' => 'category',
        'slug' => 'localized-update',
        'translations' => [
            'en' => [
                'name' => 'Updated English',
                'description' => 'Patched description',
            ],
        ],
        'expected_revision' => $term->revision,
    ], SystemActor::Background);

    expect($patched->translations->pluck('locale')->all())
        ->toEqualCanonicalizing(['en', 'bg'])
        ->and($patched->displayName('bg'))->toBe('Български');

    $replaced = app(TaxonomyTerms::class)->update($patched, [
        'taxonomy' => 'category',
        'slug' => 'localized-update',
        'translations' => [
            'en' => ['name' => 'English only'],
        ],
        'expected_revision' => $patched->revision,
    ], SystemActor::Background, TranslationSyncMode::Replace);

    expect($replaced->translations->pluck('locale')->all())->toBe(['en'])
        ->and($replaced->displayName('en'))->toBe('English only');
});

it('stores localized copy only in dedicated translation rows', function () {
    config()->set('nvl-translatable.locales', ['en', 'bg']);
    config()->set('nvl-translatable.fallback_locales', ['en']);

    $term = app(TaxonomyTerms::class)->create([
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
    ], SystemActor::Background);

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
    config()->set('nvl-taxonomy.connection', $connection);
    config()->set('nvl-translatable.locales', ['en']);

    try {
        $termsMigration = require __DIR__.'/../database/migrations/2026_01_01_000000_nvl_taxonomy_create_terms_table.php';
        $translationsMigration = require __DIR__.'/../database/migrations/2026_07_26_083129_nvl_taxonomy_create_terms_i18n_table.php';
        $termsMigration->up();
        $translationsMigration->up();

        expect(fn () => app(TaxonomyTerms::class)->create([
            'taxonomy' => 'category',
            'slug' => 'invalid-copy',
            'translations' => [
                'en' => [
                    'name' => 'Invalid copy',
                    'undeclared' => 'must fail',
                ],
            ],
        ], SystemActor::Background))->toThrow(InvalidTranslatableFieldException::class)
            ->and(Term::query()->count())->toBe(0);
    } finally {
        DB::purge($connection);
        unlink($database);
    }
});

it('uses the request scoped content locale when creating open vocabulary terms', function () {
    config()->set('nvl-translatable.locales', ['en', 'bg']);
    app(ContentLocale::class)->set('bg');

    $post = Post::create(['title' => 'Localized post']);
    app(TaxonomyAssignments::class)->sync($post, 'tag', ['Новини'], SystemActor::Background);

    $tag = $post->tags()->firstOrFail();

    expect($tag->hasTranslation('bg'))->toBeTrue()
        ->and($tag->displayName('bg'))->toBe('Новини');
});

it('uses UUID keys, prevents cycles and stale writes, and exposes a healthy doctor', function () {
    config()->set('nvl-translatable.locales', ['en']);
    $create = app(TaxonomyTerms::class);
    $parent = $create->create([
        'taxonomy' => 'category',
        'slug' => 'parent',
        'translations' => ['en' => ['name' => 'Parent']],
    ], SystemActor::Background);
    $child = $create->create([
        'taxonomy' => 'category',
        'slug' => 'child',
        'parent_id' => $parent->id,
        'translations' => ['en' => ['name' => 'Child']],
    ], SystemActor::Background);
    $revision = $parent->revision;

    expect($parent->id)->toBeUuid()
        ->and($parent->parent_id)->toBeNull()
        ->and(fn () => app(TaxonomyTerms::class)->move($parent, $child->id, SystemActor::Background, 0, $revision))->toThrow(CircularHierarchyException::class);

    $updated = app(TaxonomyTerms::class)->update($parent, [
        'taxonomy' => 'category',
        'slug' => 'parent-updated',
        'translations' => ['en' => ['name' => 'Updated']],
        'expected_revision' => $revision,
    ], SystemActor::Background);

    expect(fn () => app(TaxonomyTerms::class)->update($parent, [
        'taxonomy' => 'category',
        'slug' => 'stale',
        'translations' => ['en' => ['name' => 'Stale']],
        'expected_revision' => $revision,
    ], SystemActor::Background))->toThrow(StaleTermVersionException::class)
        ->and($updated->revision)->toBeGreaterThan($revision)
        ->and(collect(app(TaxonomyDoctor::class)->inspect())
            ->reject(static fn ($check): bool => $check->passed)
            ->mapWithKeys(static fn ($check): array => [$check->key => $check->message])
            ->all())->toBe([]);
});

it('synchronizes registered owner attachments and enforces deletion strategies', function () {
    config()->set('nvl-translatable.locales', ['en']);
    $post = Post::create(['title' => 'Attachment owner']);
    $term = app(TaxonomyTerms::class)->create([
        'taxonomy' => 'tag',
        'slug' => 'laravel',
        'translations' => ['en' => ['name' => 'Laravel']],
    ], SystemActor::Background);

    app(TaxonomyAssignments::class)->sync($post, 'tag', [$term], SystemActor::Background);

    expect($post->fresh()?->tags)->toHaveCount(1)
        ->and($term->entries(Post::class)->get())->toHaveCount(1)
        ->and($term->entries(Post::class)->first()?->getKey())->toBe($post->getKey())
        ->and(fn () => app(TaxonomyTerms::class)->delete($term, $term->revision, SystemActor::Background))->toThrow(UnsafeTermDeletionException::class)
        ->and(app(TaxonomyTerms::class)->delete($term, $term->revision, SystemActor::Background, DeleteTermStrategy::Detach))->toBeTrue();
});

it('isolates attachment mutations by owner and vocabulary', function () {
    $firstPost = Post::create(['title' => 'First owner']);
    $secondPost = Post::create(['title' => 'Second owner']);
    $category = Category::create(['slug' => 'isolated-category']);

    app(TaxonomyAssignments::class)->sync($firstPost, 'tag', ['shared-tag'], SystemActor::Background);
    app(TaxonomyAssignments::class)->sync($secondPost, 'tag', ['shared-tag'], SystemActor::Background);
    app(TaxonomyAssignments::class)->sync($firstPost, 'category', [$category], SystemActor::Background);
    app(TaxonomyAssignments::class)->detach($firstPost, 'tag', [], SystemActor::Background);

    expect($firstPost->fresh()?->tags)->toHaveCount(0)
        ->and($firstPost->fresh()?->categories)->toHaveCount(1)
        ->and($secondPost->fresh()?->tags)->toHaveCount(1)
        ->and(DB::table(TaxonomyTables::Termables)->count())->toBe(2);
});

it('reparents children and cascades complete subtrees through explicit delete strategies', function () {
    $destination = Category::create(['slug' => 'destination']);
    $reparentedRoot = Category::create(['slug' => 'reparented-root']);
    $reparentedChild = Category::create([
        'slug' => 'reparented-child',
        'parent_id' => $reparentedRoot->id,
    ]);

    expect(app(TaxonomyTerms::class)->delete($reparentedRoot, $reparentedRoot->revision, SystemActor::Background, DeleteTermStrategy::Reparent, $destination->id))->toBeTrue()
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
    app(TaxonomyAssignments::class)->sync($post, 'category', [$cascadeLeaf], SystemActor::Background);

    expect(app(TaxonomyTerms::class)->delete($cascadeRoot, $cascadeRoot->revision, SystemActor::Background, DeleteTermStrategy::Cascade))->toBeTrue()
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
    app(TaxonomyAssignments::class)->sync($post, 'tag', ['laravel'], SystemActor::Background);

    $row = DB::table(TaxonomyTables::get(TaxonomyTables::Termables))->first();

    expect($row)->not->toBeNull()
        ->and($row->id)->toBeUuid()
        ->and($row->termable_type)->toBe(Post::class)
        ->and($post->getMorphClass())->toBe(Post::class);
});

it('resolves raw attachment relations through custom owner primary keys', function () {
    $post = CustomKeyPost::create([
        'post_key' => 'custom-post-1',
        'title' => 'Custom key owner',
    ]);

    app(TaxonomyAssignments::class)->sync($post, 'tag', ['laravel'], SystemActor::Background);

    expect($post->tags()->sole()->slug)->toBe('laravel')
        ->and($post->termables()->sole()->termable_id)->toBe('custom-post-1');
});

it('uses the UUID pivot model for relation-level attachment compatibility', function () {
    $post = Post::create(['title' => 'Relation owner']);
    $tag = Tag::create(['slug' => 'relation-tag']);
    $post->tags()->attach($tag, ['taxonomy' => 'tag', 'position' => 0]);

    $row = DB::table(TaxonomyTables::get(TaxonomyTables::Termables))->first();

    expect($row?->id)->toBeUuid()
        ->and($row?->termable_type)->toBe(Post::class);
});

it('rejects multiple exclusive terms without replacing the current attachment', function () {
    $post = Post::create(['title' => 'Exclusive category']);
    $first = Category::create(['slug' => 'first']);
    $second = Category::create(['slug' => 'second']);

    app(TaxonomyAssignments::class)->sync($post, 'category', [$first], SystemActor::Background);

    expect(fn () => app(TaxonomyAssignments::class)->sync($post, 'category', [$first, $second], SystemActor::Background))->toThrow(InvalidArgumentException::class)
        ->and($post->fresh()?->categories()->pluck(TaxonomyTables::get(TaxonomyTables::Terms).'.id')->all())->toBe([$first->id]);
});

it('requires optimistic revisions for every update', function () {
    config()->set('nvl-translatable.locales', ['en']);
    $term = app(TaxonomyTerms::class)->create([
        'taxonomy' => 'tag',
        'slug' => 'revisioned',
        'translations' => ['en' => ['name' => 'Revisioned']],
    ], SystemActor::Background);

    app(TaxonomyTerms::class)->update($term, [
        'taxonomy' => 'tag',
        'slug' => 'revisioned',
        'translations' => ['en' => ['name' => 'Changed']],
    ], SystemActor::Background);
})->throws(StaleTermVersionException::class);

it('validates the deepest descendant when moving a subtree', function () {
    $root = Category::create(['slug' => 'root']);
    $middle = Category::create(['slug' => 'middle', 'parent_id' => $root->id]);
    $leaf = Category::create(['slug' => 'leaf', 'parent_id' => $middle->id]);
    $otherRoot = Category::create(['slug' => 'other']);

    expect($leaf->ancestors()->pluck('id')->all())->toBe([$middle->id, $root->id])
        ->and(fn () => app(TaxonomyTerms::class)->update($middle, ['taxonomy' => 'category', 'slug' => 'middle', 'translations' => ['en' => ['name' => 'Middle']], 'parent_id' => $otherRoot->id, 'expected_revision' => $middle->revision], SystemActor::Background))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(TaxonomyTerms::class)->move($root, $otherRoot->id, SystemActor::Background, 0, $root->revision))->toThrow(MaximumDepthExceededException::class);
});

it('requires UUID references when hierarchical slugs are ambiguous', function () {
    $firstRoot = Category::create(['slug' => 'first-root']);
    $secondRoot = Category::create(['slug' => 'second-root']);
    Category::create(['slug' => 'shared', 'parent_id' => $firstRoot->id]);
    Category::create(['slug' => 'shared', 'parent_id' => $secondRoot->id]);

    app(TaxonomyReader::class)->resolve('category', ['shared'], SystemActor::Background);
})->throws(AmbiguousTermReferenceException::class);

it('normalizes root positions independently for each taxonomy', function () {
    $tag = Tag::create(['slug' => 'tag-root', 'position' => 8]);
    $category = Category::create(['slug' => 'category-root', 'position' => 9]);

    expect(app(TaxonomyTerms::class)->rebuild(SystemActor::Background, dryRun: true))->toBe(2)
        ->and($tag->refresh()->position)->toBe(8)
        ->and($category->refresh()->position)->toBe(9)
        ->and(app(TaxonomyTerms::class)->rebuild(SystemActor::Background))->toBe(2)
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
    config()->set('nvl-translatable.locales', ['en']);
    config()->set('nvl-taxonomy.limits.metadata_depth', 2);

    expect(fn () => app(TaxonomyTerms::class)->create([
        'taxonomy' => 'tag',
        'slug' => 'Bad Slug',
        'translations' => ['en' => ['name' => 'Bad']],
    ], SystemActor::Background))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(TaxonomyTerms::class)->create([
            'taxonomy' => 'tag',
            'slug' => 'deep',
            'meta' => ['one' => ['two' => ['three' => true]]],
            'translations' => ['en' => ['name' => 'Deep']],
        ], SystemActor::Background))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(TaxonomyTerms::class)->create(['taxonomy' => 'tag', 'slug' => 'untranslated', 'translations' => []], SystemActor::Background))->toThrow(InvalidArgumentException::class);
});

it('checks bulk limits before creating open-vocabulary terms', function () {
    config()->set('nvl-taxonomy.limits.bulk_terms', 1);
    $post = Post::create(['title' => 'Limited']);

    expect(fn () => app(TaxonomyAssignments::class)->sync($post, 'tag', ['one', 'two'], SystemActor::Background))->toThrow(InvalidArgumentException::class)
        ->and(Tag::query()->count())->toBe(0);
});

it('preserves mixed reference order and rejects sibling collisions on moves', function () {
    $post = Post::create(['title' => 'Ordered terms']);
    $existing = Tag::create(['slug' => 'existing']);
    app(TaxonomyAssignments::class)->sync($post, 'tag', ['created-first', $existing], SystemActor::Background);

    $firstRoot = Category::create(['slug' => 'first-root']);
    $secondRoot = Category::create(['slug' => 'second-root']);
    Category::create(['slug' => 'duplicate', 'parent_id' => $firstRoot->id]);
    $moving = Category::create(['slug' => 'duplicate', 'parent_id' => $secondRoot->id]);

    expect($post->tags()->pluck('slug')->all())->toBe(['created-first', 'existing'])
        ->and(fn () => app(TaxonomyTerms::class)->move($moving, $firstRoot->id, SystemActor::Background, 0, $moving->revision))->toThrow(DuplicateSiblingSlugException::class);
});

it('supports generic trees, no-op unknown detaches, and merge invariants', function () {
    $post = Post::create(['title' => 'Merge owner']);
    $source = Category::create(['slug' => 'source']);
    $destination = Category::create(['slug' => 'destination']);
    $child = Category::create(['slug' => 'child', 'parent_id' => $source->id]);
    app(TaxonomyAssignments::class)->sync($post, 'category', [$source], SystemActor::Background);

    expect(app(TaxonomyAssignments::class)->detach($post, 'category', ['unknown'], SystemActor::Background))->toBe(0);

    $merged = app(TaxonomyTerms::class)->merge($source, $destination, SystemActor::Background, $source->revision, $destination->revision);
    $tree = app(TaxonomyTree::class)->for('category');

    expect($post->fresh()?->categories()->first()?->id)->toBe($destination->id)
        ->and($child->refresh()->parent_id)->toBe($destination->id)
        ->and($merged)->toBeInstanceOf(Category::class)
        ->and($merged->revision)->toBeGreaterThan($destination->revision)
        ->and($tree->pluck('id')->all())->toBe([$destination->id]);
});

it('loads localized trees without per-node translation queries', function () {
    config()->set('nvl-translatable.locales', ['en']);
    $create = app(TaxonomyTerms::class);
    $root = $create->create([
        'taxonomy' => 'category',
        'slug' => 'query-root',
        'translations' => ['en' => ['name' => 'Query root']],
    ], SystemActor::Background);
    $measure = static function (): array {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $tree = app(TaxonomyTree::class)->for('category', 'en');
        $queryCount = count(DB::getQueryLog());
        $names = $tree
            ->flatMap(static fn (Term $term): array => [
                $term->displayName('en'),
                ...$term->children
                    ->map(static fn (Term $child): string => $child->displayName('en'))
                    ->all(),
            ])
            ->all();

        expect(DB::getQueryLog())->toHaveCount($queryCount);
        DB::disableQueryLog();

        return [$queryCount, $names];
    };
    [$singleQueryCount] = $measure();

    foreach (range(1, 24) as $index) {
        $create->create([
            'taxonomy' => 'category',
            'slug' => "query-child-{$index}",
            'parent_id' => $root->id,
            'translations' => ['en' => ['name' => "Query child {$index}"]],
        ], SystemActor::Background);
    }

    [$populatedQueryCount, $names] = $measure();

    expect($names)->toHaveCount(25)
        ->and($names[0])->toBe('Query root')
        ->and($singleQueryCount)->toBeLessThanOrEqual(2)
        ->and($populatedQueryCount)->toBe($singleQueryCount);
});

it('answers display copy for the current locale from the preloaded tree copy', function () {
    config()->set('nvl-translatable.locales', ['en', 'bg']);
    app(TaxonomyTerms::class)->create([
        'taxonomy' => 'category',
        'slug' => 'preloaded-root',
        'translations' => ['en' => ['name' => 'Preloaded', 'description' => 'Preloaded detail']],
    ], SystemActor::Background);
    $term = app(TaxonomyTree::class)->for('category', 'en')->sole();
    $term->setRelation('translations', $term->translations->map(static function (Model $row): Model {
        return $row->setAttribute('name', 'Changed after preload')->setAttribute('description', 'Changed detail');
    }));

    expect($term->displayName())->toBe('Preloaded')
        ->and($term->displayDescription())->toBe('Preloaded detail')
        ->and($term->displayName('en'))->toBe('Preloaded')
        ->and($term->displayName('bg'))->toBe('Changed after preload');
});

it('discards term events when an outer transaction rolls back', function () {
    config()->set('nvl-translatable.locales', ['en']);
    Event::fake([TermChanged::class]);
    $connection = DB::connection((new Term)->getConnectionName());
    $initialTransactionLevel = $connection->transactionLevel();
    $connection->beginTransaction();

    try {
        app(TaxonomyTerms::class)->create([
            'taxonomy' => 'tag',
            'slug' => 'rolled-back-event',
            'translations' => ['en' => ['name' => 'Rolled back']],
        ], SystemActor::Background);

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
    config()->set('nvl-translatable.locales', ['en']);
    Event::fake([TermChanged::class]);

    $term = app(TaxonomyTerms::class)->create([
        'taxonomy' => 'tag',
        'slug' => 'committed-event',
        'translations' => ['en' => ['name' => 'Committed']],
    ], SystemActor::Background);

    Event::assertDispatched(
        TermChanged::class,
        static fn (TermChanged $event): bool => $event->termId === $term->id
            && $event->taxonomy === 'tag'
            && $event->operation === TermChangeOperation::Created
            && $event->revision === $term->revision,
    );
    Event::assertDispatchedTimes(TermChanged::class, 1);
});

/** Commit a root tag the way a concurrent request would, bypassing this request's writer. */
function taxonomyInsertCompetingTag(string $slug): void
{
    DB::table(TaxonomyTables::get(TaxonomyTables::Terms))->insert([
        'id' => (string) Str::uuid(),
        'taxonomy' => 'tag',
        'parent_key' => '__root__',
        'slug' => $slug,
        'position' => 0,
        'revision' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('reuses an open term that a concurrent request committed after the lookup', function () {
    $post = Post::create(['title' => 'Concurrent tagger']);
    $committed = false;
    Gate::define('taxonomy.create', function () use (&$committed): bool {
        if (! $committed) {
            $committed = true;
            taxonomyInsertCompetingTag('race-tag');
        }

        return true;
    });

    app(TaxonomyAssignments::class)->sync($post, 'tag', ['race-tag'], SystemActor::Background);

    expect($committed)->toBeTrue()
        ->and(Tag::query()->where('slug', 'race-tag')->count())->toBe(1)
        ->and($post->load('tags')->tags->pluck('slug')->all())->toBe(['race-tag']);
});

it('reports a root slug race lost at the unique index as a duplicate sibling slug', function () {
    $competing = null;
    Tag::creating(function (Tag $tag) use (&$competing): void {
        if ($competing === null) {
            $competing = $tag->slug;
            taxonomyInsertCompetingTag($tag->slug);
        }
    });

    expect(fn () => app(TaxonomyTerms::class)->create([
        'taxonomy' => 'tag',
        'slug' => 'raced-root',
        'translations' => ['en' => ['name' => 'Raced root']],
    ], SystemActor::Background))->toThrow(DuplicateSiblingSlugException::class)
        ->and($competing)->toBe('raced-root');
});

it('does not swallow non-unique database failures while creating open terms', function () {
    $translationTable = TaxonomyTables::get(TaxonomyTables::I18n);
    config()->set(
        'nvl-taxonomy.tables.i18n',
        'taxonomy_translation_failure',
    );
    $post = Post::create(['title' => 'Failed open term']);

    try {
        expect(fn () => app(TaxonomyAssignments::class)->sync($post, 'tag', ['must-rollback'], SystemActor::Background))->toThrow(QueryException::class)
            ->and(Tag::query()->where('slug', 'must-rollback')->exists())->toBeFalse()
            ->and(DB::table(TaxonomyTables::Termables)->count())->toBe(0);
    } finally {
        config()->set('nvl-taxonomy.tables.i18n', $translationTable);
    }
});

it('rejects stale term and owner instances before writing attachments', function () {
    $post = Post::create(['title' => 'Live owner']);
    $staleTerm = Tag::create(['slug' => 'stale-term']);
    Tag::query()->whereKey($staleTerm->id)->delete();

    expect(fn () => app(TaxonomyAssignments::class)->sync($post, 'tag', [$staleTerm], SystemActor::Background))->toThrow(InvalidArgumentException::class)
        ->and(DB::table(TaxonomyTables::Termables)->count())->toBe(0);

    $staleOwner = Post::create(['title' => 'Stale owner']);
    Post::query()->whereKey($staleOwner->getKey())->delete();

    expect(fn () => app(TaxonomyAssignments::class)->sync($staleOwner, 'tag', ['must-not-exist'], SystemActor::Background))->toThrow(InvalidArgumentException::class)
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
    config()->set('nvl-translatable.locales', ['en']);
    config()->set('nvl-taxonomy.limits.description_chars', 3);

    expect(fn () => app(TaxonomyTerms::class)->create([
        'taxonomy' => 'tag',
        'slug' => 'c59c7d68-2b00-45b5-b926-66ba5157e830',
        'translations' => ['en' => ['name' => 'Reserved']],
    ], SystemActor::Background))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(TaxonomyTerms::class)->create(['taxonomy' => 'tag', 'slug' => 'bounded-description', 'translations' => ['en' => ['name' => 'Bounded', 'description' => 'four']]], SystemActor::Background))->toThrow(InvalidArgumentException::class);
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
    $termsTable = strtolower(
        DB::connection()->getQueryGrammar()->wrapTable(TaxonomyTables::get(TaxonomyTables::Terms)),
    );

    app(TaxonomyTerms::class)->validateMerge($source, $destination, SystemActor::Background, $source->revision, $destination->revision);

    $termReads = collect(DB::getQueryLog())
        ->filter(static fn (array $query): bool => str_contains(
            strtolower($query['query']),
            'from '.$termsTable,
        ))
        ->count();
    DB::disableQueryLog();

    expect($termReads)->toBe(4);
});

it('rejects negative move positions and invalid doctor formats', function () {
    $term = Category::create(['slug' => 'positioned']);

    expect(fn () => app(TaxonomyTerms::class)->move($term, null, SystemActor::Background, -1, $term->revision))->toThrow(InvalidArgumentException::class);

    $this->artisan('nvl:taxonomy:doctor', ['--format' => 'yaml'])
        ->expectsOutput('The taxonomy doctor format must be [text] or [json].')
        ->assertFailed();
});

it('preserves configured term subclasses across hierarchy relations', function () {
    $parent = Category::create(['slug' => 'typed-parent']);
    $child = Category::create(['slug' => 'typed-child', 'parent_id' => $parent->id]);
    $descendant = $parent->descendants()->first();
    $moved = app(TaxonomyTerms::class)->move($child, null, SystemActor::Background, 0, $child->revision);

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
    config()->set('nvl-taxonomy.connection', $connection);
    config()->set('nvl-translatable.locales', ['en']);

    try {
        foreach ([
            '2026_01_01_000000_nvl_taxonomy_create_terms_table.php',
            '2026_01_01_000001_nvl_taxonomy_create_termables_table.php',
            '2026_07_26_083129_nvl_taxonomy_create_terms_i18n_table.php',
        ] as $migration) {
            (require __DIR__.'/../database/migrations/'.$migration)->up();
        }

        $create = app(TaxonomyTerms::class);
        $firstRoot = $create->create([
            'taxonomy' => 'category',
            'slug' => 'first-root',
            'translations' => ['en' => ['name' => 'First root']],
        ], SystemActor::Background);
        $secondRoot = $create->create([
            'taxonomy' => 'category',
            'slug' => 'second-root',
            'translations' => ['en' => ['name' => 'Second root']],
        ], SystemActor::Background);
        $child = $create->create([
            'taxonomy' => 'category',
            'slug' => 'child',
            'parent_id' => $firstRoot->id,
            'translations' => ['en' => ['name' => 'Child']],
        ], SystemActor::Background);
        $moved = app(TaxonomyTerms::class)->move($child, $secondRoot->id, SystemActor::Background, 0, $child->revision);
        $source = $create->create([
            'taxonomy' => 'tag',
            'slug' => 'source',
            'translations' => ['en' => ['name' => 'Source']],
        ], SystemActor::Background);
        $destination = $create->create([
            'taxonomy' => 'tag',
            'slug' => 'destination',
            'translations' => ['en' => ['name' => 'Destination']],
        ], SystemActor::Background);
        Post::migrate();
        $post = Post::create(['title' => 'Source owner']);
        app(TaxonomyAssignments::class)->sync($post, 'tag', [$source], SystemActor::Background);
        app(TaxonomyTerms::class)->merge($source, $destination, SystemActor::Background, $source->revision, $destination->revision);
        app(TaxonomyTerms::class)->delete($moved, $moved->revision, SystemActor::Background, DeleteTermStrategy::Restrict);

        expect($moved->getConnectionName())->toBe($connection)
            ->and(DB::connection($connection)->table(TaxonomyTables::get(TaxonomyTables::Terms))->where('id', $child->id)->exists())->toBeFalse()
            ->and(DB::connection($connection)->table(TaxonomyTables::get(TaxonomyTables::Termables))->value('term_id'))->toBe($destination->id)
            ->and(DB::connection()->table(TaxonomyTables::get(TaxonomyTables::Terms))->count())->toBe(0);
    } finally {
        config()->set('nvl-taxonomy.connection', null);
        DB::purge($connection);
        unlink($database);
    }
});

it('validates flat and hierarchical writes without hydrating unrelated vocabulary terms', function (): void {
    $terms = app(TaxonomyTerms::class);
    $actor = SystemActor::Background;
    $unrelated = $terms->create(['taxonomy' => 'tag', 'slug' => 'unrelated-tag', 'translations' => ['en' => ['name' => 'Unrelated']]], $actor);
    $category = $terms->create(['taxonomy' => 'category', 'slug' => 'parent-category', 'translations' => ['en' => ['name' => 'Parent']]], $actor);
    $otherCategory = $terms->create(['taxonomy' => 'category', 'slug' => 'other-category', 'translations' => ['en' => ['name' => 'Other']]], $actor);
    $retrieved = [];
    Event::listen('eloquent.retrieved: *', function (string $event, array $models) use (&$retrieved): void {
        foreach ($models as $model) {
            if ($model instanceof Term) {
                $retrieved[] = $model->id;
            }
        }
    });

    $tag = $terms->create(['taxonomy' => 'tag', 'slug' => 'new-tag', 'translations' => ['en' => ['name' => 'New']]], $actor);
    $terms->update($tag, ['position' => 2, 'expected_revision' => $tag->revision], $actor);
    $terms->create(['taxonomy' => 'category', 'slug' => 'new-child', 'parent_id' => $category->id, 'translations' => ['en' => ['name' => 'Child']]], $actor);

    expect($retrieved)->not->toContain($unrelated->id, $otherCategory->id);
});

it('preserves attachment identities and creation times when appending and reordering terms', function (): void {
    $owner = Post::create(['title' => 'Stable attachment owner']);
    $assignments = app(TaxonomyAssignments::class);
    $actor = SystemActor::Background;
    $assignments->sync($owner, 'tag', ['one', 'two'], $actor);
    $table = TaxonomyTables::get(TaxonomyTables::Termables);
    $before = DB::table($table)->where('termable_id', $owner->id)->orderBy('position')->get()->keyBy('term_id');
    $assignments->attach($owner, 'tag', ['three'], $actor);
    $afterAppend = DB::table($table)->where('termable_id', $owner->id)->orderBy('position')->get()->keyBy('term_id');
    foreach ($before as $termId => $attachment) {
        expect($afterAppend[$termId]->id)->toBe($attachment->id)
            ->and($afterAppend[$termId]->created_at)->toBe($attachment->created_at);
    }

    $assignments->sync($owner, 'tag', ['three', 'one'], $actor);
    $afterReorder = DB::table($table)->where('termable_id', $owner->id)->orderBy('position')->get();
    expect($afterReorder->pluck('position')->all())->toBe([0, 1])
        ->and($afterReorder[1]->id)->toBe($before->first()->id);
});

it('limits locked descendant reads to the affected taxonomy subtree', function (): void {
    $terms = app(TaxonomyTerms::class);
    $actor = SystemActor::Background;
    $root = $terms->create(['taxonomy' => 'category', 'slug' => 'subtree-root', 'translations' => ['en' => ['name' => 'Root']]], $actor);
    $child = $terms->create(['taxonomy' => 'category', 'slug' => 'subtree-child', 'parent_id' => $root->id, 'translations' => ['en' => ['name' => 'Child']]], $actor);
    $unrelated = $terms->create(['taxonomy' => 'category', 'slug' => 'unrelated-root', 'translations' => ['en' => ['name' => 'Other']]], $actor);
    $seen = [];
    Event::listen('eloquent.retrieved: *', function (string $event, array $models) use (&$seen): void {
        foreach ($models as $model) {
            if ($model instanceof Term) {
                $seen[] = $model->id;
            }
        }
    });
    DB::transaction(function () use ($root, $child): void {
        $root->newQuery()->whereKey($root->id)->lockForUpdate()->firstOrFail();
        expect(app(TermHierarchy::class)->descendants($root)->modelKeys())->toBe([$child->id])
            ->and(app(TermHierarchy::class)->subtreeDepth($root))->toBe(2);
    });

    expect($seen)->not->toContain($unrelated->id);
});
