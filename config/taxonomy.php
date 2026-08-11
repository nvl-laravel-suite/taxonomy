<?php

declare(strict_types=1);

use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Models\Category;
use Nvl\Taxonomy\Models\Tag;
use Nvl\Taxonomy\Support\SlugGenerator;

return [

    'owners' => [
        // 'articles' => \Domain\Content\Article::class,
    ],

    'taxonomies' => [

        'tag' => [
            'model' => Tag::class,
            'hierarchical' => false,
            'exclusive' => false,
            'open' => true,
            'sort' => 'position',
            'allowed_owners' => [],
            'metadata_rules' => [],
        ],

        'category' => [
            'model' => Category::class,
            'hierarchical' => true,
            'exclusive' => true,
            'open' => false,
            'max_depth' => 3,
            'sort' => 'position',
            'allowed_owners' => [],
            'metadata_rules' => [],
        ],

    ],

    'table_names' => [
        TaxonomyTables::Terms => TaxonomyTables::Terms,
        TaxonomyTables::I18n => TaxonomyTables::I18n,
        TaxonomyTables::Termables => TaxonomyTables::Termables,
    ],

    'storage' => [
        'connection' => null,
    ],

    'migrations' => [
        'enabled' => true,
    ],

    'limits' => [
        'metadata_bytes' => 65536,
        'metadata_depth' => 8,
        'description_chars' => 10000,
        'bulk_terms' => 500,
    ],

    'transactions' => [
        'attempts' => 3,
    ],

    'locks' => [
        'seconds' => 30,
        'wait_seconds' => 10,
    ],

    'slugs' => [
        'generator' => SlugGenerator::class,
        'locale' => null,
    ],

];
