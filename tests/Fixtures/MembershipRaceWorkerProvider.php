<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Contracts\TaxonomyMembershipPolicy;
use Nvl\Taxonomy\Enums\TaxonomyMembershipMode;
use Nvl\Taxonomy\Models\Category;
use Nvl\Taxonomy\Models\Tag;
use Nvl\Taxonomy\Support\TaxonomyDefinition;
use Nvl\Taxonomy\Support\TaxonomyMembershipProposal;

/** Rebuilds the parent test's committed PostgreSQL source, owners, Gates and applying policy in each race worker. */
final class MembershipRaceWorkerProvider extends ServiceProvider
{
    /** @var array<string, mixed> */
    public static array $source = [];

    /** Configure the worker before the package providers boot. */
    public function register(): void
    {
        $source = self::$source;
        // The parent's name would make models resolve a different connection than the default.
        unset($source['name']);
        $this->app->make('config')->set([
            'app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'database.default' => 'taxonomy_race',
            'database.connections.taxonomy_race' => $source,
            'nvl-taxonomy.migrations.enabled' => false,
            'nvl-taxonomy.taxonomies' => [
                'tag' => ['model' => Tag::class, 'hierarchical' => false, 'exclusive' => false, 'open' => true],
                'category' => ['model' => Category::class, 'hierarchical' => true, 'exclusive' => true, 'open' => false, 'max_depth' => 3],
            ],
            'nvl-taxonomy.owners' => ['posts' => Post::class],
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ]);
        $this->app->instance(TaxonomyMembershipPolicy::class, new class implements TaxonomyMembershipPolicy
        {
            public function mode(Model $owner, TaxonomyDefinition $taxonomy, Authenticatable|SystemActor $actor, TaxonomyMembershipProposal $proposal): TaxonomyMembershipMode
            {
                return TaxonomyMembershipMode::Apply;
            }
        });
    }

    /** Admit only the background actor, as the package test case does. */
    public function boot(): void
    {
        foreach (['create', 'assign', 'resolve', 'read', 'delete'] as $ability) {
            Gate::define('taxonomy.'.$ability, static fn (mixed $actor): bool => $actor === SystemActor::Background);
        }
    }
}
