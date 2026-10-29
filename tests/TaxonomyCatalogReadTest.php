<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Contracts\TaxonomyCatalogAuthorization;
use Nvl\Taxonomy\Exceptions\UnknownTaxonomyException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TaxonomyReader;
use Nvl\Taxonomy\Services\TaxonomyTerms;
use Nvl\Taxonomy\Tests\TestCase;
use Nvl\Translatable\Services\ContentLocale;

uses(TestCase::class);

beforeEach(function (): void {
    config()->set('nvl-translatable.locales', ['en', 'bg']);
    config()->set('nvl-translatable.fallback_locales', ['en']);
});

it('projects an unattached localized catalogue with parent identities metadata and stable order', function (): void {
    $create = app(TaxonomyTerms::class);
    $root = $create->create(['taxonomy' => 'category', 'slug' => 'food', 'translations' => ['en' => ['name' => 'Food', 'description' => 'Shared description'], 'bg' => ['name' => 'Храна', 'description' => null]], 'meta' => ['icon' => 'utensils', 'flags' => ['curated' => true]]], SystemActor::Background);
    $later = $create->create(['taxonomy' => 'category', 'slug' => 'later', 'translations' => ['en' => ['name' => 'Later']], 'parent_id' => $root->id, 'position' => 2], SystemActor::Background);
    $first = $create->create(['taxonomy' => 'category', 'slug' => 'first', 'translations' => ['en' => ['name' => 'First']], 'parent_id' => $root->id, 'position' => 1], SystemActor::Background);
    $reader = app(TaxonomyReader::class);
    $tree = $reader->tree('category', SystemActor::Background, 'bg');
    $display = $tree->sole();

    expect($display)->toBeInstanceOf(Term::class)
        ->and($display->id)->toBe($root->id)
        ->and($display->taxonomy)->toBe('category')
        ->and($display->displayName('bg'))->toBe('Храна')
        ->and($display->displayDescription('bg'))->toBe('Shared description')
        ->and($display->parent_id)->toBeNull()
        ->and($display->revision)->toBe(1)
        ->and($display->meta)->toBe(['icon' => 'utensils', 'flags' => ['curated' => true]])
        ->and($display->children->pluck('id')->all())->toBe([$first->id, $later->id])
        ->and($display->children[0]->parent_id)->toBe($root->id)
        ->and($display->children[0]->displayName('bg'))->toBe('First')
        ->and($reader->term('category', $first->id, SystemActor::Background, 'bg')->parent_id)->toBe($root->id)
        ->and($reader->term('tag', $first->id, SystemActor::Background, 'bg'))->toBeNull();

    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect($display->children[0]->displayName('bg'))->toBe('First')
            ->and(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
});

it('uses the scoped content locale and preserves empty display copy', function (): void {
    $term = app(TaxonomyTerms::class)->create(['taxonomy' => 'tag', 'slug' => 'empty-description', 'translations' => ['en' => ['name' => 'Tag', 'description' => 'English'], 'bg' => ['name' => 'Етикет', 'description' => '']]], SystemActor::Background);
    app(ContentLocale::class)->set('bg');
    $display = app(TaxonomyReader::class)->term('tag', $term->id, SystemActor::Background);

    expect($display->displayName('bg'))->toBe('Етикет')
        ->and($display->displayDescription('bg'))->toBe('')
        ->and($display->meta)->toBeNull()
        ->and($display->relationLoaded('children'))->toBeFalse();
});

it('rejects denied catalogue vocabularies before reading term storage', function (): void {
    app()->bind(TaxonomyCatalogAuthorization::class, static fn () => new class implements TaxonomyCatalogAuthorization
    {
        public function authorizeVocabulary(Authenticatable|SystemActor $actor, string $taxonomy): void
        {
            throw new AuthorizationException('Denied vocabulary.');
        }
    });
    $reader = app(TaxonomyReader::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(fn () => $reader->tree('category', SystemActor::Background))->toThrow(AuthorizationException::class)
            ->and(fn () => $reader->term('category', 'unknown', SystemActor::Background))->toThrow(AuthorizationException::class)
            ->and(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
});

it('refuses unknown catalogue vocabularies and missing identities explicitly', function (): void {
    $reader = app(TaxonomyReader::class);

    expect(fn () => $reader->tree('undeclared', SystemActor::Background))->toThrow(UnknownTaxonomyException::class)
        ->and(fn () => $reader->term('undeclared', 'unknown', SystemActor::Background))->toThrow(UnknownTaxonomyException::class)
        ->and($reader->term('category', '00000000-0000-4000-8000-000000000000', SystemActor::Background))->toBeNull();
});

it('rejects malformed catalogue identities before database-specific UUID queries', function (string $id): void {
    $reader = app(TaxonomyReader::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(fn () => $reader->term('category', $id, SystemActor::Background))->toThrow(InvalidArgumentException::class)
            ->and(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
})->with(['', 'unknown']);

it('refuses oversized catalogue trees without returning a partial hierarchy', function (): void {
    $create = app(TaxonomyTerms::class);
    $root = $create->create(['taxonomy' => 'category', 'slug' => 'root', 'translations' => ['en' => ['name' => 'Root']]], SystemActor::Background);
    $create->create(['taxonomy' => 'category', 'slug' => 'child', 'translations' => ['en' => ['name' => 'Child']], 'parent_id' => $root->id], SystemActor::Background);
    $reader = app(TaxonomyReader::class);

    expect(fn () => $reader->tree('category', SystemActor::Background, 'en', limit: 1))->toThrow(InvalidArgumentException::class, 'limit')
        ->and($reader->tree('category', SystemActor::Background, 'en', limit: 2)->sole()->children)->toHaveCount(1);
});

it('requires a finite catalogue tree bound', function (int $limit): void {
    expect(fn () => app(TaxonomyReader::class)->tree('category', SystemActor::Background, limit: $limit))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1, 501, PHP_INT_MAX]);
