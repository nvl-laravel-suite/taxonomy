<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Taxonomy\Models\Category;
use Nvl\Taxonomy\Models\Tag;
use Nvl\Taxonomy\Providers\TaxonomyServiceProvider;
use Nvl\Taxonomy\Tests\Fixtures\CustomKeyPost;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Taxonomy and its runtime dependencies in an isolated Laravel application.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * Return providers required by the isolated taxonomy package application.
     *
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DataServiceProvider::class,
            TranslatableServiceProvider::class,
            TaxonomyServiceProvider::class,
        ];
    }

    /**
     * Configure built-in vocabularies and registered test owner aliases.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('taxonomy.taxonomies', [
            'tag' => ['model' => Tag::class, 'hierarchical' => false, 'exclusive' => false, 'open' => true],
            'category' => ['model' => Category::class, 'hierarchical' => true, 'exclusive' => true, 'open' => false, 'max_depth' => 3],
        ]);
        $app['config']->set('taxonomy.owners', [
            'custom_key_posts' => CustomKeyPost::class,
            'posts' => Post::class,
        ]);
    }
}
