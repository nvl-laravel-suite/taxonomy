<?php

declare(strict_types=1);

use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Services\DisabledTenantBoundary;
use Nvl\Taxonomy\Contracts\ListOwnerTaxonomyTermsContract;
use Nvl\Taxonomy\Models\Category;
use Nvl\Taxonomy\Models\Tag;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Models\TermTranslation;
use Nvl\Taxonomy\Tests\Fixtures\Post;

it('reads registered term subclasses with only required providers', function (string $operation): void {
    expect($this->app->providerIsLoaded('Nvl\\Tenancy\\Providers\\TenancyServiceProvider'))->toBeFalse()
        ->and(app(TenantBoundary::class))->toBeInstanceOf(DisabledTenantBoundary::class);
    $owner = Post::query()->create(['title' => 'Standalone owner']);
    $ids = [];
    foreach (['tag', 'category'] as $vocabulary) {
        $term = Term::query()->create(['taxonomy' => $vocabulary, 'slug' => 'visible-'.$vocabulary]);
        $ids[$vocabulary] = $term->id;
        TermTranslation::query()->create(['term_id' => $term->id, 'locale' => 'en', 'name' => 'Visible '.$vocabulary]);
        Termable::query()->create([
            'term_id' => $term->id, 'termable_type' => $owner->getMorphClass(),
            'termable_id' => (string) $owner->getKey(), 'taxonomy' => $vocabulary, 'position' => 3,
        ]);
    }

    if ($operation === 'batch') {
        $result = app(ListOwnerTaxonomyTermsContract::class)->execute([$owner], ['tag', 'category'], 'en');
        $terms = $result->owners->{$owner->getMorphClass()}->{(string) $owner->getKey()}->vocabularies;
        expect($terms->tag[0]->id)->toBe($ids['tag'])
            ->and($terms->tag[0]->name)->toBe('Visible tag')
            ->and($terms->tag[0]->position)->toBe(3)
            ->and($terms->category[0]->id)->toBe($ids['category'])
            ->and($terms->category[0]->name)->toBe('Visible category');

        return;
    }

    expect(Tag::query()->whereKey($ids['tag'])->exists())->toBeTrue();
    $category = Category::query()->findOrFail($ids['category']);
    expect(Post::query()->withAnyTerms('tag', ['visible-tag'])->pluck('id')->all())->toBe([$owner->getKey()])
        ->and(Post::query()->withAllTerms('tag', ['visible-tag', $ids['tag']])->pluck('id')->all())->toBe([$owner->getKey()])
        ->and(Post::query()->withoutTerms('tag', ['missing'])->pluck('id')->all())->toBe([$owner->getKey()])
        ->and(Post::query()->inCategory($category, false)->pluck('id')->all())->toBe([$owner->getKey()]);
})->with(['batch', 'filters']);
