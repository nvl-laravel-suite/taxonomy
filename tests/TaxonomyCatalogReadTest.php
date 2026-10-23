<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Nvl\Taxonomy\Actions\CreateTermAction;
use Nvl\Taxonomy\Contracts\TaxonomyCatalogAuthorization;
use Nvl\Taxonomy\Contracts\TaxonomyCatalogReader;
use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Taxonomy\Data\TaxonomyTermDisplay;
use Nvl\Taxonomy\Exceptions\UnknownTaxonomyException;
use Nvl\Taxonomy\Tests\TestCase;
use Nvl\Translatable\Services\ContentLocale;

uses(TestCase::class);

beforeEach(function (): void {
    config()->set('nvl-translatable.locales', ['en', 'bg']);
    config()->set('nvl-translatable.fallback_locales', ['en']);
});

it('projects an unattached localized catalogue with parent identities metadata and stable order', function (): void {
    $create = app(CreateTermAction::class);
    $root = $create->execute(new MutateTermPayload(
        taxonomy: 'category', slug: 'food',
        translations: ['en' => ['name' => 'Food', 'description' => 'Shared description'], 'bg' => ['name' => 'Храна', 'description' => null]],
        meta: ['icon' => 'utensils', 'flags' => ['curated' => true]],
    ));
    $later = $create->execute(new MutateTermPayload('category', 'later', ['en' => ['name' => 'Later']], parentId: $root->id, position: 2));
    $first = $create->execute(new MutateTermPayload('category', 'first', ['en' => ['name' => 'First']], parentId: $root->id, position: 1));
    $reader = app(TaxonomyCatalogReader::class);
    $tree = $reader->tree('category', 'bg');
    $display = $tree->sole();

    expect($display)->toBeInstanceOf(TaxonomyTermDisplay::class)
        ->and($display->id)->toBe($root->id)
        ->and($display->vocabulary)->toBe('category')
        ->and($display->name)->toBe('Храна')
        ->and($display->description)->toBe('Shared description')
        ->and($display->parentId)->toBeNull()
        ->and($display->revision)->toBe(1)
        ->and($display->meta)->toBe(['icon' => 'utensils', 'flags' => ['curated' => true]])
        ->and(array_column($display->children, 'id'))->toBe([$first->id, $later->id])
        ->and($display->children[0]->parentId)->toBe($root->id)
        ->and($display->children[0]->name)->toBe('First')
        ->and($reader->term('category', $first->id, 'bg')->parentId)->toBe($root->id)
        ->and($reader->term('tag', $first->id, 'bg'))->toBeNull();

    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect($display->toArray()['children'][0]['name'])->toBe('First')
            ->and(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
});

it('uses the scoped content locale and preserves empty display copy', function (): void {
    $term = app(CreateTermAction::class)->execute(new MutateTermPayload(
        'tag', 'empty-description', ['en' => ['name' => 'Tag', 'description' => 'English'], 'bg' => ['name' => 'Етикет', 'description' => '']],
    ));
    app(ContentLocale::class)->set('bg');
    $display = app(TaxonomyCatalogReader::class)->term('tag', $term->id);

    expect($display->name)->toBe('Етикет')
        ->and($display->description)->toBe('')
        ->and($display->meta)->toBeNull()
        ->and($display->children)->toBe([]);
});

it('rejects denied catalogue vocabularies before reading term storage', function (): void {
    app()->bind(TaxonomyCatalogAuthorization::class, static fn () => new class implements TaxonomyCatalogAuthorization
    {
        public function authorizeVocabulary(string $taxonomy): void
        {
            throw new AuthorizationException('Denied vocabulary.');
        }
    });
    $reader = app(TaxonomyCatalogReader::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(fn () => $reader->tree('category'))->toThrow(AuthorizationException::class)
            ->and(fn () => $reader->term('category', 'unknown'))->toThrow(AuthorizationException::class)
            ->and(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
});

it('refuses unknown catalogue vocabularies and missing identities explicitly', function (): void {
    $reader = app(TaxonomyCatalogReader::class);

    expect(fn () => $reader->tree('undeclared'))->toThrow(UnknownTaxonomyException::class)
        ->and(fn () => $reader->term('undeclared', 'unknown'))->toThrow(UnknownTaxonomyException::class)
        ->and($reader->term('category', '00000000-0000-4000-8000-000000000000'))->toBeNull();
});

it('rejects malformed catalogue identities before database-specific UUID queries', function (string $id): void {
    $reader = app(TaxonomyCatalogReader::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(fn () => $reader->term('category', $id))->toThrow(InvalidArgumentException::class)
            ->and(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
})->with(['', 'unknown']);

it('refuses oversized catalogue trees without returning a partial hierarchy', function (): void {
    $create = app(CreateTermAction::class);
    $root = $create->execute(new MutateTermPayload('category', 'root', ['en' => ['name' => 'Root']]));
    $create->execute(new MutateTermPayload('category', 'child', ['en' => ['name' => 'Child']], parentId: $root->id));
    $reader = app(TaxonomyCatalogReader::class);

    expect(fn () => $reader->tree('category', 'en', limit: 1))->toThrow(InvalidArgumentException::class, 'limit')
        ->and($reader->tree('category', 'en', limit: 2)->sole()->children)->toHaveCount(1);
});

it('requires a finite catalogue tree bound', function (int $limit): void {
    expect(fn () => app(TaxonomyCatalogReader::class)->tree('category', limit: $limit))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1, 501, PHP_INT_MAX]);
