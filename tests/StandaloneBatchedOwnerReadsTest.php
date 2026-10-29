<?php

declare(strict_types=1);

use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Models\Category;
use Nvl\Taxonomy\Models\Tag;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Models\TermTranslation;
use Nvl\Taxonomy\Services\TaxonomyReader;
use Nvl\Taxonomy\Tests\Fixtures\Post;

it('reads registered term subclasses with only required providers', function (string $operation): void {
    expect($this->app->providerIsLoaded('Nvl\\Tenancy\\Providers\\TenancyServiceProvider'))->toBeFalse();
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
        $result = app(TaxonomyReader::class)->forOwners([$owner], ['tag', 'category'], SystemActor::Background, 'en');
        $terms = $result['owners']->{$owner->getMorphClass()}->{(string) $owner->getKey()};
        expect($terms->tag[0]->id)->toBe($ids['tag'])
            ->and($terms->tag[0]->displayName('en'))->toBe('Visible tag')
            ->and($terms->tag[0]->pivot_position)->toBe(3)
            ->and($terms->category[0]->id)->toBe($ids['category'])
            ->and($terms->category[0]->displayName('en'))->toBe('Visible category');

        return;
    }

    expect(Tag::query()->whereKey($ids['tag'])->exists())->toBeTrue();
    $category = Category::query()->findOrFail($ids['category']);
    expect(Post::query()->withAnyTerms('tag', ['visible-tag'], SystemActor::Background)->pluck('id')->all())->toBe([$owner->getKey()])
        ->and(Post::query()->withAllTerms('tag', ['visible-tag', $ids['tag']], SystemActor::Background)->pluck('id')->all())->toBe([$owner->getKey()])
        ->and(Post::query()->withoutTerms('tag', ['missing'], SystemActor::Background)->pluck('id')->all())->toBe([$owner->getKey()])
        ->and(Post::query()->inCategory($category, SystemActor::Background, false)->pluck('id')->all())->toBe([$owner->getKey()]);
})->with(['batch', 'filters']);
