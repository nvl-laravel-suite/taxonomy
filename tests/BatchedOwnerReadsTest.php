<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Taxonomy\Actions\CreateTermAction;
use Nvl\Taxonomy\Actions\SyncTermAttachmentsAction;
use Nvl\Taxonomy\Contracts\ListOwnerTaxonomyTermsContract;
use Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization;
use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Taxonomy\Exceptions\TaxonomyBatchReadException;
use Nvl\Taxonomy\Exceptions\TaxonomyException;
use Nvl\Taxonomy\Exceptions\UnknownTaxonomyException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Models\TermTranslation;
use Nvl\Taxonomy\Services\TaxonomyBatchOwnerAdmission;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Taxonomy\Support\TaxonomyDefinition;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Taxonomy\Tests\Fixtures\BatchArchivedPost;
use Nvl\Taxonomy\Tests\Fixtures\BatchAttachmentTableOwner;
use Nvl\Taxonomy\Tests\Fixtures\BatchScopedPost;
use Nvl\Taxonomy\Tests\Fixtures\BatchScopedTerm;
use Nvl\Taxonomy\Tests\Fixtures\BatchTaxonomyPolicy;
use Nvl\Taxonomy\Tests\Fixtures\BatchTermTableOwner;
use Nvl\Taxonomy\Tests\Fixtures\CustomKeyPost;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Tenancy\Services\TenantInstallationState;
use Nvl\Translatable\Enums\TranslationFallbackPolicy;

beforeEach(function (): void {
    $driver = getenv('NVL_C1_TEST_DRIVER');
    if (is_string($driver) && $driver !== '') {
        expect(DB::connection()->getDriverName())->toBe($driver);
    }

    $this->taxonomyBatchMorphMap = Relation::morphMap();
    $this->taxonomyBatchRequiresMorphMap = Relation::requiresMorphMap();
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
    config()->set([
        'nvl-translatable.locales' => ['en', 'bg', 'fr'],
        'nvl-translatable.default_locale' => 'en',
        'nvl-translatable.fallback_locales' => ['en'],
    ]);
});

afterEach(function (): void {
    DB::disableQueryLog();
    Relation::morphMap($this->taxonomyBatchMorphMap, false);
    Relation::requireMorphMap($this->taxonomyBatchRequiresMorphMap);
});

/**
 * Create canonical structural fixtures through the public mutation boundary.
 *
 * @param  array<string, array{name: string, description?: string|null}>  $translations
 */
function taxonomyBatchTerm(string $slug, array $translations = [], string $vocabulary = 'tag', ?string $parentId = null): Term
{
    return app(CreateTermAction::class)->execute(MutateTermPayload::from([
        'taxonomy' => $vocabulary,
        'slug' => $slug,
        'parentId' => $parentId,
        'translations' => $translations === [] ? ['en' => ['name' => ucfirst($slug)]] : $translations,
    ]));
}

/**
 * Capture every SQL statement in one public reader invocation.
 *
 * @template T
 *
 * @param  Closure(): T  $read
 * @return array{0: T, 1: list<array{query: string, bindings: array<mixed>, time: float}>}
 */
function taxonomyBatchCapture(Closure $read): array
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        $result = $read();

        return [$result, DB::getQueryLog()];
    } finally {
        DB::disableQueryLog();
    }
}

/**
 * Detect denied package SQL using actual model table names across identifier grammars.
 *
 * @param  list<array{query: string, bindings: array<mixed>, time: float}>  $queries
 * @return list<array{query: string, bindings: array<mixed>, time: float}>
 */
function taxonomyBatchOwnedQueries(array $queries): array
{
    $tables = [(new Term)->getTable(), (new Termable)->getTable(), (new TermTranslation)->getTable()];

    return array_values(array_filter($queries, static function (array $query) use ($tables): bool {
        $sql = str_replace(['"', '`', '[', ']'], '', strtolower($query['query']));

        foreach ($tables as $table) {
            if (preg_match('/\\b(?:from|join)\\s+'.preg_quote(strtolower($table), '/').'\\b/', $sql) === 1) {
                return true;
            }
        }

        return false;
    }));
}

/** Register a concrete vocabulary with its existing canonical owner capability. */
function taxonomyBatchRegister(string $name, string $model = Term::class, array $allowedOwners = []): void
{
    app(TaxonomyRegistry::class)->register(new TaxonomyDefinition(
        taxonomy: $name,
        model: $model,
        hierarchical: true,
        exclusive: false,
        open: false,
        maxDepth: 0,
        sort: 'position',
        allowedOwners: $allowedOwners,
        metadataRules: [],
    ));
}

/**
 * Temporarily use configured physical tables and restore them on every outcome.
 *
 * @return Closure(list<string>): void
 */
function taxonomyBatchConfigureOverlapTables(bool $configured): Closure
{
    if (! $configured) {
        return static function (array $termIds): void {};
    }
    $configuration = config('nvl-taxonomy.tables');
    $tables = [
        'terms' => [(new Term)->getTable(), 'batch_overlap_terms'],
        'termables' => [(new Termable)->getTable(), 'batch_overlap_termables'],
    ];
    foreach ($tables as $key => [$original, $replacement]) {
        Schema::rename($original, $replacement);
        config()->set('nvl-taxonomy.tables.'.$key, $replacement);
    }

    return static function (array $termIds) use ($tables, $configuration): void {
        DB::disableQueryLog();
        DB::table((new Termable)->getTable())->whereIn('term_id', $termIds)->delete();
        DB::table((new Term)->getTable())->whereIn('id', $termIds)->delete();
        foreach (array_reverse($tables) as [$original, $replacement]) {
            Schema::rename($replacement, $original);
        }
        config()->set('nvl-taxonomy.tables', $configuration);
    };
}

/**
 * Seed two distinct owners whose physical storage overlaps an inner filter table.
 *
 * @param  class-string<Model>  $class
 * @return array{0: list<Model>, 1: Term, 2: Term}
 */
function taxonomyBatchOverlappingOwners(string $class, string $vocabulary = 'tag'): array
{
    app(TaxonomyOwnerRegistry::class)->register('overlap_owners', $class);
    $first = taxonomyBatchTerm('overlap-first', vocabulary: $vocabulary);
    $second = taxonomyBatchTerm('overlap-second', vocabulary: $vocabulary);
    $ids = $class === BatchTermTableOwner::class ? [$first->id, $second->id] : [(string) Str::uuid(), (string) Str::uuid()];
    foreach ([$first, $second] as $index => $term) {
        Termable::query()->create([
            'id' => $class === BatchAttachmentTableOwner::class ? $ids[$index] : (string) Str::uuid(),
            'term_id' => $term->id,
            'taxonomy' => $vocabulary,
            'termable_type' => $class,
            'termable_id' => $ids[$index],
            'position' => 0,
        ]);
    }
    $owners = [(new $class)->newQuery()->findOrFail($ids[0]), (new $class)->newQuery()->findOrFail($ids[1])];

    return [$owners, $first, $second];
}

it('returns an empty object map without reading storage', function (): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    [$result, $queries] = taxonomyBatchCapture(fn () => $reader->execute([], ['tag', 'category']));
    $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

    expect($queries)->toBe([])
        ->and($json->owners)->toBeInstanceOf(stdClass::class)
        ->and(get_object_vars($json->owners))->toBe([])
        ->and($json->order)->toBe([]);
});

it('keeps total localized query counts fixed at one twenty five and one hundred owners', function (): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    $first = taxonomyBatchTerm('first', ['en' => ['name' => 'English first', 'description' => 'English detail'], 'bg' => ['name' => 'Български', 'description' => null]]);
    $second = taxonomyBatchTerm('second', ['en' => ['name' => 'English second']]);
    $owners = [];
    for ($index = 0; $index < 100; $index++) {
        $owner = Post::query()->create(['title' => 'Owner '.$index]);
        $owners[] = $owner;
        app(SyncTermAttachmentsAction::class)->execute($owner, 'tag', [$second, $first]);
    }

    $reader->execute([$owners[0]], ['tag', 'category'], 'bg');
    $counts = [];
    foreach ([1, 25, 100] as $count) {
        [$result, $queries] = taxonomyBatchCapture(fn () => $reader->execute(array_slice($owners, 0, $count), ['tag', 'category'], 'bg'));
        $counts[] = count($queries);
        $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        expect($json->order)->toHaveCount($count);

        foreach (array_slice($owners, 0, $count) as $owner) {
            $vocabularies = $json->owners->{$owner->getMorphClass()}->{(string) $owner->getKey()}->vocabularies;
            expect($vocabularies)->toBeInstanceOf(stdClass::class)
                ->and($vocabularies->category)->toBe([])
                ->and(array_column($vocabularies->tag, 'id'))->toBe([$second->id, $first->id])
                ->and(array_column($vocabularies->tag, 'position'))->toBe([0, 1])
                ->and($vocabularies->tag[0]->name)->toBe('English second')
                ->and($vocabularies->tag[1]->name)->toBe('Български')
                ->and($vocabularies->tag[1]->description)->toBe('English detail')
                ->and(get_object_vars($vocabularies->tag[0]))->toHaveKeys(['id', 'vocabulary', 'slug', 'parentId', 'position', 'name', 'description'])
                ->not->toHaveKeys(['translations', 'meta', 'parent', 'children', 'path']);
        }
    }

    expect($counts)->toBe([4, 4, 4]);
});

it('preserves first request order numeric objects and explicit vocabulary empties', function (): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    $first = Post::query()->create(['title' => 'First']);
    $second = Post::query()->create(['title' => 'Second']);
    $result = $reader->execute([$second, $first, $second], ['category', 'tag'], 'en');
    $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

    expect($json->owners)->toBeInstanceOf(stdClass::class)
        ->and($json->owners->{Post::class})->toBeInstanceOf(stdClass::class)
        ->and($json->order)->toEqual([
            (object) ['type' => Post::class, 'id' => (string) $second->getKey()],
            (object) ['type' => Post::class, 'id' => (string) $first->getKey()],
        ])
        ->and(array_keys(get_object_vars($json->owners->{Post::class}->{(string) $first->getKey()}->vocabularies)))->toBe(['category', 'tag'])
        ->and($json->owners->{Post::class}->{(string) $first->getKey()}->vocabularies->tag)->toBe([]);
});

it('keeps exact native and custom morph identities paired with string owner keys', function (): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    Relation::morphMap(['Host.Custom' => CustomKeyPost::class], false);
    $native = Post::query()->create(['title' => 'Native']);
    $strings = [];
    foreach ([(string) $native->getKey(), '0', '007', 'Upper', 'upper', 'trail', 'trail '] as $key) {
        $strings[] = CustomKeyPost::query()->create(['post_key' => $key, 'title' => $key]);
    }

    $terms = [];
    foreach ([$native, ...$strings] as $index => $owner) {
        $term = taxonomyBatchTerm('identity-'.$index);
        $terms[] = $term;
        Termable::query()->create(['term_id' => $term->id, 'taxonomy' => 'tag', 'termable_type' => $owner->getMorphClass(), 'termable_id' => (string) $owner->getKey(), 'position' => 0]);
    }

    $result = $reader->execute([$native, ...$strings], ['tag']);
    $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

    foreach ([$native, ...$strings] as $index => $owner) {
        expect(array_column($json->owners->{$owner->getMorphClass()}->{(string) $owner->getKey()}->vocabularies->tag, 'id'))->toBe([$terms[$index]->id]);
        expect($owner->newQuery()->withAnyTerms('tag', [$terms[$index]->id])->pluck($owner->getKeyName())->all())->toBe([$owner->getKey()]);
    }
    expect(Relation::morphMap())->toBe(['Host.Custom' => CustomKeyPost::class]);
});

it('rejects overlapping storage host filters before SQL while keeping batch reads paired', function (string $class, bool $configured, string $method): void {
    $restore = taxonomyBatchConfigureOverlapTables($configured);
    try {
        [$owners, $first, $second] = taxonomyBatchOverlappingOwners($class);
        $result = app(ListOwnerTaxonomyTermsContract::class)->execute($owners, ['tag']);
        foreach ([$first, $second] as $index => $term) {
            expect(array_column($result->owners->{$class}->{(string) $owners[$index]->getKey()}->vocabularies->tag, 'id'))->toBe([$term->id]);
        }
        $query = (new $class)->newQuery()->whereKey(array_map(static fn (Model $owner): mixed => $owner->getKey(), $owners));
        DB::enableQueryLog();
        DB::flushQueryLog();
        expect(fn () => $query->{$method}('tag', [$first->id])->get())->toThrow(TaxonomyBatchReadException::class, 'ListOwnerTaxonomyTermsContract');
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        $restore(isset($first, $second) ? [$first->id, $second->id] : []);
    }
})->with([[BatchTermTableOwner::class], [BatchAttachmentTableOwner::class]])->with([false, true])->with(['withAnyTerms', 'withAllTerms', 'withoutTerms']);

it('preserves empty host filter semantics on overlapping storage without correlation', function (string $class, bool $configured): void {
    $restore = taxonomyBatchConfigureOverlapTables($configured);
    try {
        [$owners, $first, $second] = taxonomyBatchOverlappingOwners($class);
        $ids = array_map(static fn (Model $owner): mixed => $owner->getKey(), $owners);
        expect((new $class)->newQuery()->whereKey($ids)->withAnyTerms('tag', [])->pluck('id')->all())->toBe([])
            ->and((new $class)->newQuery()->whereKey($ids)->withAllTerms('tag', [])->pluck('id')->all())->toEqualCanonicalizing($ids)
            ->and((new $class)->newQuery()->whereKey($ids)->withoutTerms('tag', [])->pluck('id')->all())->toEqualCanonicalizing($ids);
    } finally {
        $restore(isset($first, $second) ? [$first->id, $second->id] : []);
    }
})->with([[BatchTermTableOwner::class], [BatchAttachmentTableOwner::class]])->with([false, true]);

it('rejects overlapping storage category filters before root or traversal SQL', function (string $class, bool $configured): void {
    $restore = taxonomyBatchConfigureOverlapTables($configured);
    try {
        [$owners, $root, $other] = taxonomyBatchOverlappingOwners($class, 'category');
        $query = (new $class)->newQuery()->whereKey(array_map(static fn (Model $owner): mixed => $owner->getKey(), $owners));
        DB::enableQueryLog();
        DB::flushQueryLog();
        expect(fn () => $query->inCategory($root)->get())->toThrow(TaxonomyBatchReadException::class, 'ListOwnerTaxonomyTermsContract');
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        $restore(isset($root, $other) ? [$root->id, $other->id] : []);
    }
})->with([[BatchTermTableOwner::class], [BatchAttachmentTableOwner::class]])->with([false, true]);

it('rejects equivalent qualified and case folded table storage before SQL', function (string $shape): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite identifier resolution proves equivalent physical table spellings.');
    }
    $table = (new Term)->getTable();
    config()->set('taxonomy-test.overlap_owner_table', $shape === 'schema' ? 'main.'.$table : strtoupper($table));
    [$owners, $first, $second] = taxonomyBatchOverlappingOwners(BatchTermTableOwner::class);
    $result = app(ListOwnerTaxonomyTermsContract::class)->execute($owners, ['tag']);
    foreach ([$first, $second] as $index => $term) {
        expect(array_column($result->owners->{BatchTermTableOwner::class}->{(string) $owners[$index]->getKey()}->vocabularies->tag, 'id'))->toBe([$term->id]);
    }
    $ids = array_map(static fn (Model $owner): mixed => $owner->getKey(), $owners);
    expect(BatchTermTableOwner::query()->whereKey($ids)->withAnyTerms('tag', [])->count())->toBe(0)
        ->and(BatchTermTableOwner::query()->whereKey($ids)->withAllTerms('tag', [])->count())->toBe(2)
        ->and(BatchTermTableOwner::query()->whereKey($ids)->withoutTerms('tag', [])->count())->toBe(2);
    DB::enableQueryLog();
    DB::flushQueryLog();
    foreach (['withAnyTerms', 'withAllTerms', 'withoutTerms'] as $method) {
        expect(fn () => BatchTermTableOwner::query()->{$method}('tag', [$first->id])->get())->toThrow(TaxonomyBatchReadException::class);
    }
    expect(fn () => BatchTermTableOwner::query()->inCategory($first)->get())->toThrow(TaxonomyBatchReadException::class);
    expect(DB::getQueryLog())->toBe([]);
})->with(['schema', 'case']);

it('uses attachment positions with deterministic term id ties', function (): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    $owner = Post::query()->create(['title' => 'Ordered']);
    $first = taxonomyBatchTerm('first');
    $second = taxonomyBatchTerm('second');
    $first->update(['position' => 90]);
    $second->update(['position' => 80]);
    app(SyncTermAttachmentsAction::class)->execute($owner, 'tag', [$second, $first]);
    Termable::query()->where('termable_type', Post::class)->where('termable_id', (string) $owner->getKey())->update(['position' => 7]);

    $result = $reader->execute([$owner], ['tag']);
    $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $ids = [$first->id, $second->id];
    sort($ids, SORT_STRING);

    expect(array_column($json->owners->{Post::class}->{(string) $owner->getKey()}->vocabularies->tag, 'id'))->toBe($ids)
        ->and(array_column($json->owners->{Post::class}->{(string) $owner->getKey()}->vocabularies->tag, 'position'))->toBe([7, 7]);
});

it('rejects invalid owners before reading package storage', function (string $mode): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    $owner = Post::query()->create(['title' => 'Canonical']);
    $owners = match ($mode) {
        'unpersisted' => [new Post(['title' => 'Unpersisted'])],
        'duplicate' => [$owner, tap(clone $owner, static fn (Post $copy) => $copy->setRawAttributes([...$copy->getAttributes(), 'title' => 'Forged'], true))],
        'foreign-connection' => [tap(clone $owner, static fn (Post $copy) => $copy->setConnection('foreign'))],
        'absent' => [tap(clone $owner, static fn (Post $copy) => $copy->setRawAttributes([...$copy->getAttributes(), 'id' => 999999], true))],
    };

    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => $reader->execute($owners, ['tag']))->toThrow(InvalidArgumentException::class);
    expect(taxonomyBatchOwnedQueries(DB::getQueryLog()))->toBe([]);
})->with(['unpersisted', 'duplicate', 'foreign-connection', 'absent']);

it('rejects undeclared vocabulary and owner capability before reading package storage', function (): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    $owner = Post::query()->create(['title' => 'Canonical']);
    taxonomyBatchRegister('restricted', allowedOwners: [CustomKeyPost::class]);

    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => $reader->execute([$owner], ['missing']))->toThrow(UnknownTaxonomyException::class)
        ->and(fn () => $reader->execute([$owner], ['restricted']))->toThrow(InvalidArgumentException::class);
    expect(taxonomyBatchOwnedQueries(DB::getQueryLog()))->toBe([]);
});

it('enforces the input and declared vocabulary limits before storage queries', function (): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    $owners = [];
    for ($index = 0; $index < 101; $index++) {
        $owners[] = Post::query()->create(['title' => 'Owner '.$index]);
    }
    $vocabularies = [];
    for ($index = 0; $index < 21; $index++) {
        $name = 'vocabulary-'.$index;
        taxonomyBatchRegister($name);
        $vocabularies[] = $name;
    }

    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => $reader->execute($owners, ['tag']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $reader->execute([$owners[0]], $vocabularies))->toThrow(InvalidArgumentException::class);
    expect(DB::getQueryLog())->toBe([]);
});

it('reloads registered owners through retained visibility and soft delete scopes', function (bool $deleted): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    Schema::table((new Post)->getTable(), static fn (Blueprint $table) => $table->softDeletes());
    app(TaxonomyOwnerRegistry::class)->register('batch_posts', BatchScopedPost::class);
    $owner = BatchScopedPost::withoutGlobalScopes()->create(['title' => $deleted ? 'visible' : 'hidden']);
    if ($deleted) {
        BatchScopedPost::withoutGlobalScopes()->whereKey($owner->getKey())->update(['deleted_at' => now()]);
    }
    $owner->setRawAttributes([...$owner->getAttributes(), 'title' => 'visible', 'deleted_at' => null], true);

    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => $reader->execute([$owner], ['tag']))->toThrow(InvalidArgumentException::class);
    expect(taxonomyBatchOwnedQueries(DB::getQueryLog()))->toBe([]);
})->with([false, true]);

it('uses the registered term model visibility before translations', function (): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    taxonomyBatchRegister('private_tags', BatchScopedTerm::class);
    $owner = Post::query()->create(['title' => 'Canonical']);
    $visible = taxonomyBatchTerm('visible', vocabulary: 'private_tags');
    $hidden = BatchScopedTerm::withoutGlobalScopes()->create(['taxonomy' => 'private_tags', 'slug' => 'hidden']);
    Termable::query()->create(['term_id' => $hidden->id, 'taxonomy' => 'private_tags', 'termable_type' => Post::class, 'termable_id' => (string) $owner->getKey(), 'position' => 1]);
    app(SyncTermAttachmentsAction::class)->execute($owner, 'private_tags', [$visible]);
    Termable::query()->create(['term_id' => $hidden->id, 'taxonomy' => 'private_tags', 'termable_type' => Post::class, 'termable_id' => (string) $owner->getKey(), 'position' => 1]);

    [$result, $queries] = taxonomyBatchCapture(fn () => $reader->execute([$owner], ['private_tags']));
    $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

    expect(array_column($json->owners->{Post::class}->{(string) $owner->getKey()}->vocabularies->private_tags, 'id'))->toBe([$visible->id]);
    $translationQueries = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], (new TermTranslation)->getTable()));
    foreach ($translationQueries as $query) {
        expect($query['bindings'])->not->toContain($hidden->id);
    }
});

it('rejects term overflow before transferring terms or translations', function (): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    $owner = Post::query()->create(['title' => 'Dense']);
    $terms = [];
    for ($index = 0; $index < 101; $index++) {
        $terms[] = taxonomyBatchTerm('dense-'.$index);
    }
    app(SyncTermAttachmentsAction::class)->execute($owner, 'tag', $terms);

    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => $reader->execute([$owner], ['tag']))->toThrow(TaxonomyException::class);
    $queries = DB::getQueryLog();
    expect(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], (new TermTranslation)->getTable())))->toBe([]);
    $windows = array_values(array_filter($queries, static fn (array $query): bool => str_contains(strtolower($query['query']), 'row_number()')));
    if ($windows !== []) {
        DB::disableQueryLog();
        expect(DB::select($windows[0]['query'], $windows[0]['bindings']))->toHaveCount(101);
    }
});

it('returns the complete permitted one hundred term payload', function (): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    $owner = Post::query()->create(['title' => 'Dense']);
    $terms = [];
    for ($index = 0; $index < 100; $index++) {
        $terms[] = taxonomyBatchTerm('dense-'.$index);
    }
    app(SyncTermAttachmentsAction::class)->execute($owner, 'tag', $terms);
    $result = $reader->execute([$owner], ['tag']);
    $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

    expect($json->owners->{Post::class}->{(string) $owner->getKey()}->vocabularies->tag)->toHaveCount(100);
});

it('groups policy OR clauses inside mandatory vocabulary and identity predicates', function (): void {
    app(ListOwnerTaxonomyTermsContract::class);
    app()->instance(TaxonomyBatchAuthorization::class, new BatchTaxonomyPolicy);
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    $owner = Post::query()->create(['title' => 'Requested']);
    $foreign = Post::query()->create(['title' => 'Foreign']);
    $visible = taxonomyBatchTerm('visible');
    $hidden = taxonomyBatchTerm('hidden');
    $category = taxonomyBatchTerm('visible', vocabulary: 'category');
    app(SyncTermAttachmentsAction::class)->execute($owner, 'tag', [$visible, $hidden]);
    app(SyncTermAttachmentsAction::class)->execute($owner, 'category', [$category]);
    app(SyncTermAttachmentsAction::class)->execute($foreign, 'tag', [$visible]);

    $result = $reader->execute([$owner], ['tag']);
    $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    expect(array_map('strval', array_keys(get_object_vars($json->owners->{Post::class}))))->toBe([(string) $owner->getKey()])
        ->and(array_column($json->owners->{Post::class}->{(string) $owner->getKey()}->vocabularies->tag, 'id'))->toBe([$visible->id]);
});

it('preserves existing filter semantics and requires visible explicit policy terms', function (): void {
    app(ListOwnerTaxonomyTermsContract::class);
    $policy = new BatchTaxonomyPolicy;
    $visibleOwner = Post::query()->create(['title' => 'Visible']);
    $hiddenOwner = Post::query()->create(['title' => 'Hidden']);
    $visible = taxonomyBatchTerm('visible');
    $hidden = taxonomyBatchTerm('hidden');
    app(SyncTermAttachmentsAction::class)->execute($visibleOwner, 'tag', [$visible]);
    app(SyncTermAttachmentsAction::class)->execute($hiddenOwner, 'tag', [$hidden]);

    expect(Post::query()->withAnyTerms('tag', [], $policy)->count())->toBe(0)
        ->and(Post::query()->withAllTerms('tag', [], $policy)->count())->toBe(2)
        ->and(Post::query()->withoutTerms('tag', [], $policy)->count())->toBe(2)
        ->and(Post::query()->withAllTerms('tag', [$visible->id, 'visible'], $policy)->sole()->getKey())->toBe($visibleOwner->getKey())
        ->and(Post::query()->withAnyTerms('tag', ['hidden'], $policy)->count())->toBe(0)
        ->and(Post::query()->withoutTerms('tag', ['hidden'], $policy)->count())->toBe(2)
        ->and(Post::query()->select('id')->where('title', 'Hidden')->withAnyTerms('tag', ['visible'], $policy)->count())->toBe(0);
});

it('does not traverse a hidden registered category root through the generic term model', function (): void {
    app(ListOwnerTaxonomyTermsContract::class);
    taxonomyBatchRegister('private_categories', BatchScopedTerm::class);
    $owner = Post::query()->create(['title' => 'Owner']);
    $root = BatchScopedTerm::withoutGlobalScopes()->create(['taxonomy' => 'private_categories', 'slug' => 'hidden']);
    $child = BatchScopedTerm::withoutGlobalScopes()->create(['taxonomy' => 'private_categories', 'slug' => 'visible', 'parent_id' => $root->id]);
    app(SyncTermAttachmentsAction::class)->execute($owner, 'private_categories', [$child]);

    expect(fn () => Post::query()->inCategory($root)->get())->toThrow(InvalidArgumentException::class);
});

it('rejects altered host builder connection table and unions before SQL', function (string $mode): void {
    app(ListOwnerTaxonomyTermsContract::class);
    $query = Post::query();
    if ($mode === 'connection') {
        config()->set('database.connections.foreign', config('database.connections.'.DB::getDefaultConnection()));
        $query->getQuery()->connection = DB::connection('foreign');
    } elseif ($mode === 'table') {
        $query->from((new Post)->getTable().' as disguised');
    } else {
        $query->union(Post::query());
    }

    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => $query->withAnyTerms('tag', ['visible'])->get())->toThrow(InvalidArgumentException::class);
    expect(DB::getQueryLog())->toBe([]);
})->with(['connection', 'table', 'union']);

it('preserves empty localized copy and finite any available locale fallback', function (): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    config()->set('nvl-translatable.fallback.policy', TranslationFallbackPolicy::AnyAvailable->value);
    $owner = Post::query()->create(['title' => 'Localized']);
    $empty = taxonomyBatchTerm('empty', ['en' => ['name' => 'Fallback', 'description' => 'Fallback detail'], 'bg' => ['name' => 'Legacy name', 'description' => '']]);
    TermTranslation::query()->where('term_id', $empty->id)->where('locale', 'bg')->update(['name' => '']);
    $french = taxonomyBatchTerm('french', ['fr' => ['name' => 'Français', 'description' => null]]);
    app(SyncTermAttachmentsAction::class)->execute($owner, 'tag', [$empty, $french]);

    $result = $reader->execute([$owner], ['tag'], 'bg');
    $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $terms = $json->owners->{Post::class}->{(string) $owner->getKey()}->vocabularies->tag;

    expect($terms[0]->name)->toBe('')
        ->and($terms[0]->description)->toBe('')
        ->and($terms[1]->name)->toBe('Français')
        ->and($terms[1]->description)->toBeNull();
});

it('measures cold and warm total SQL budgets for one and two populated vocabularies', function (int $count, int $vocabularyCount): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    taxonomyBatchRegister('secondary');
    $first = taxonomyBatchTerm('first');
    $second = taxonomyBatchTerm('second', vocabulary: 'secondary');
    $owners = [];
    for ($index = 0; $index < $count; $index++) {
        $owner = Post::query()->create(['title' => 'Owner '.$index]);
        $owners[] = $owner;
        app(SyncTermAttachmentsAction::class)->execute($owner, 'tag', [$first]);
        app(SyncTermAttachmentsAction::class)->execute($owner, 'secondary', [$second]);
    }
    $vocabularies = $vocabularyCount === 1 ? ['tag'] : ['tag', 'secondary'];
    app(TenantInstallationState::class)->invalidate();
    [, $cold] = taxonomyBatchCapture(fn () => $reader->execute($owners, $vocabularies, 'en'));
    [, $warm] = taxonomyBatchCapture(fn () => $reader->execute($owners, $vocabularies, 'en'));

    expect(count($cold))->toBe($vocabularyCount === 1 ? 5 : 7)
        ->and(count($warm))->toBe($vocabularyCount === 1 ? 4 : 6);
})->with([[1, 1], [25, 1], [100, 1], [1, 2], [25, 2], [100, 2]]);

it('groups caller OR clauses before native and custom soft delete guards in direct helpers', function (string $class, string $column): void {
    Schema::table((new Post)->getTable(), static fn (Blueprint $table) => $table->softDeletes($column));
    app(TaxonomyOwnerRegistry::class)->register('batch_live', $class);
    $active = $class::withoutGlobalScopes()->create(['title' => 'visible']);
    $deleted = $class::withoutGlobalScopes()->create(['title' => 'deleted']);
    $class::withoutGlobalScopes()->whereKey($deleted->getKey())->update([$column => now()]);
    $query = $class::withoutGlobalScopes()->select('id', 'title')->selectRaw('? as marker', ['retained'])
        ->where('title', 'visible')->orWhere('title', 'deleted')
        ->orderByRaw('CASE WHEN title = ? THEN 0 ELSE 1 END', ['visible']);
    $columns = $query->getQuery()->columns;
    $bindings = $query->getBindings();
    $guarded = app(TaxonomyBatchOwnerAdmission::class)->scope($query);

    expect($guarded->getQuery()->columns)->toBe($columns)
        ->and($guarded->getBindings())->toBe($bindings)
        ->and($guarded->get()->modelKeys())->toBe([$active->getKey()])
        ->and($guarded->firstOrFail()->getAttribute('marker'))->toBe('retained')
        ->and($class::withoutGlobalScopes()->where('title', 'visible')->orWhere('title', 'deleted')->withAllTerms('tag', [])->pluck('id')->all())->toBe([$active->getKey()]);
})->with([[BatchScopedPost::class, 'deleted_at'], [BatchArchivedPost::class, 'archived_at']]);

it('bounds category traversal by visible child sets rather than loading a vocabulary', function (): void {
    $root = taxonomyBatchTerm('root', vocabulary: 'category');
    for ($index = 0; $index < 3; $index++) {
        taxonomyBatchTerm('child-'.$index, vocabulary: 'category', parentId: $root->id);
    }
    config()->set('nvl-taxonomy.limits.bulk_terms', 3);
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => Post::query()->inCategory($root)->get())->toThrow(TaxonomyException::class);
    $queries = DB::getQueryLog();
    $childQueries = array_values(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'parent_id')));
    expect($childQueries)->toHaveCount(1)
        ->and($childQueries[0]['query'])->toContain('limit 3')
        ->and($childQueries[0]['bindings'])->toContain($root->id);
});

it('adds one reload query per owner class while custom term models keep the vocabulary budget', function (): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    taxonomyBatchRegister('private_tags', BatchScopedTerm::class);
    $native = Post::query()->create(['title' => 'Native']);
    $custom = CustomKeyPost::query()->create(['post_key' => '007', 'title' => 'Custom']);
    $tag = taxonomyBatchTerm('visible');
    $private = taxonomyBatchTerm('visible', vocabulary: 'private_tags');
    foreach ([$native, $custom] as $owner) {
        app(SyncTermAttachmentsAction::class)->execute($owner, 'tag', [$tag]);
        app(SyncTermAttachmentsAction::class)->execute($owner, 'private_tags', [$private]);
    }
    $reader->execute([$native, $custom], ['tag', 'private_tags'], 'en');
    [, $oneVocabulary] = taxonomyBatchCapture(fn () => $reader->execute([$native, $custom], ['tag'], 'en'));
    [, $twoVocabularies] = taxonomyBatchCapture(fn () => $reader->execute([$native, $custom], ['tag', 'private_tags'], 'en'));

    expect(count($oneVocabulary))->toBe(5)
        ->and(count($twoVocabularies))->toBe(7);
});

it('keeps term policy OR clauses inside the independent host attachment policy', function (): void {
    app()->instance(TaxonomyBatchAuthorization::class, new BatchTaxonomyPolicy);
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    $owner = Post::query()->create(['title' => 'Denied attachment']);
    $term = taxonomyBatchTerm('absent');
    Termable::query()->create(['term_id' => $term->id, 'taxonomy' => 'tag', 'termable_type' => Post::class, 'termable_id' => (string) $owner->getKey(), 'position' => 1]);
    $result = $reader->execute([$owner], ['tag']);
    $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

    expect($json->owners->{Post::class}->{(string) $owner->getKey()}->vocabularies->tag)->toBe([])
        ->and(Post::query()->withAnyTerms('tag', ['absent'], new BatchTaxonomyPolicy)->count())->toBe(0);
});

it('does not count denied structural terms toward overflow or translation loads', function (): void {
    app()->instance(TaxonomyBatchAuthorization::class, new BatchTaxonomyPolicy(false));
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    $owner = Post::query()->create(['title' => 'Dense private vocabulary']);
    $visible = taxonomyBatchTerm('visible');
    $terms = [$visible];
    for ($index = 0; $index < 101; $index++) {
        $terms[] = taxonomyBatchTerm('denied-'.$index);
    }
    app(SyncTermAttachmentsAction::class)->execute($owner, 'tag', $terms);
    [$result, $queries] = taxonomyBatchCapture(fn () => $reader->execute([$owner], ['tag']));
    $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    expect(array_column($json->owners->{Post::class}->{(string) $owner->getKey()}->vocabularies->tag, 'id'))->toBe([$visible->id]);
    $windows = array_values(array_filter($queries, static fn (array $query): bool => str_contains(strtolower($query['query']), 'row_number()')));
    expect(DB::select($windows[0]['query'], $windows[0]['bindings']))->toHaveCount(1);
});

it('bounds transferred successful attachments separately for every exact owner vocabulary group', function (): void {
    $reader = app(ListOwnerTaxonomyTermsContract::class);
    taxonomyBatchRegister('secondary');
    $native = Post::query()->create(['title' => 'Native']);
    $custom = CustomKeyPost::query()->create(['post_key' => '007', 'title' => 'Custom']);
    foreach (['tag', 'secondary'] as $vocabulary) {
        $terms = [];
        for ($index = 0; $index < 100; $index++) {
            $terms[] = taxonomyBatchTerm('term-'.$index, vocabulary: $vocabulary);
        }
        foreach ([$native, $custom] as $owner) {
            app(SyncTermAttachmentsAction::class)->execute($owner, $vocabulary, $terms);
        }
    }
    [$result, $queries] = taxonomyBatchCapture(fn () => $reader->execute([$native, $custom], ['tag', 'secondary']));
    $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    foreach ([$native, $custom] as $owner) {
        foreach (['tag', 'secondary'] as $vocabulary) {
            expect($json->owners->{$owner->getMorphClass()}->{(string) $owner->getKey()}->vocabularies->{$vocabulary})->toHaveCount(100);
        }
    }
    $windows = array_values(array_filter($queries, static fn (array $query): bool => str_contains(strtolower($query['query']), 'row_number()')));
    expect(DB::select($windows[0]['query'], $windows[0]['bindings']))->toHaveCount(400)
        ->and(count($queries))->toBe(7);
});
