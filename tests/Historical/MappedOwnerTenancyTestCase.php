<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Taxonomy\Tests\Fixtures\Post;

/** Exercises class-list capabilities against an explicitly host-owned morph map. */
abstract class MappedOwnerTenancyTestCase extends TaxonomyTenancyTestCase
{
    /** @var array<string, class-string<Model>> */
    private array $originalMorphMap = [];

    /** Configure the host identity before package providers inspect capabilities. */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $this->originalMorphMap = Relation::morphMap();
        Relation::morphMap(['host.taxonomy-owner' => Post::class]);
        $app['config']->set('nvl-core.owners', [Post::class]);
        $app['config']->set('nvl-taxonomy.owners', [Post::class]);
        $app['config']->set('nvl-taxonomy.taxonomies.tag.allowed_owners', [Post::class]);
    }

    /** Preserve the external host map after every fixture, including boot failures. */
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            Relation::morphMap($this->originalMorphMap, false);
        }
    }
}
