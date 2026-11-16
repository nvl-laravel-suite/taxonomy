<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Models\Category;
use Nvl\Taxonomy\Models\Tag;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TaxonomyTerms;
use Nvl\Taxonomy\Tests\TestCase;

uses(TestCase::class);

it('preserves specialized binding identity', function (): void {
    $terms = app(TaxonomyTerms::class);
    $category = $terms->create(['taxonomy' => 'category', 'slug' => 'shared', 'translations' => ['en' => ['name' => 'Shared']]], SystemActor::Background);
    $tag = $terms->create(['taxonomy' => 'tag', 'slug' => 'shared', 'translations' => ['en' => ['name' => 'Shared']]], SystemActor::Background);

    expect((new Category)->resolveRouteBinding($category->id))->toBeInstanceOf(Category::class)
        ->and((new Category)->resolveRouteBinding($category->id)?->id)->toBe($category->id)
        ->and((new Category)->resolveRouteBinding($tag->id))->toBeNull()
        ->and((new Tag)->resolveRouteBinding($category->id))->toBeNull()
        ->and((new Tag)->resolveRouteBinding('shared', 'slug'))->toBeInstanceOf(Tag::class)
        ->and((new Tag)->resolveRouteBinding('shared', 'slug')?->id)->toBe($tag->id)
        ->and((new Term)->resolveRouteBinding($tag->id)::class)->toBe(Term::class);

    Route::middleware(SubstituteBindings::class)->get('/categories/{category:slug}', static fn (Category $category): array => [$category::class, $category->id]);

    $this->get('/categories/shared')->assertOk()->assertExactJson([Category::class, $category->id]);
});
