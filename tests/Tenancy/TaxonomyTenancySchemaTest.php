<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Taxonomy\Tests\Fixtures\TaxonomyTenancyScenario;

it('uses configured storage aliases and tenant-leading tree constraints', function (): void {
    TaxonomyTenancyScenario::install();

    expect((new Term)->getTable())->toBe('tenant_terms')
        ->and((new Term)->getConnectionName())->toBe('taxonomy_tenant_fixture')
        ->and(Schema::hasTable('tenant_term_translations'))->toBeTrue()
        ->and(Schema::hasTable('tenant_termables'))->toBeTrue()
        ->and(Schema::hasIndex('tenant_terms', 'terms_tenant_sibling_slug_unique'))->toBeTrue()
        ->and(array_any(
            Schema::getForeignKeys('tenant_terms'),
            static fn (array $foreign): bool => $foreign['name'] === 'terms_tenant_parent_foreign',
        ))->toBeTrue();
});

it('rejects a raw attachment whose term belongs to another tenant', function (): void {
    $scenario = TaxonomyTenancyScenario::install();
    $term = $scenario->term($scenario::A);
    /** @var Post $owner */
    $owner = $scenario->owner($scenario::B);

    expect(fn () => DB::table('tenant_termables')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $scenario::B,
        'term_id' => $term->id,
        'termable_type' => $owner->getMorphClass(),
        'termable_id' => (string) $owner->getKey(),
        'taxonomy' => 'tag',
        'position' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('reverses and reapplies final taxonomy ownership constraints', function (): void {
    TaxonomyTenancyScenario::install();
    $migration = require dirname(__DIR__, 2).'/database/tenancy/2026_09_16_120002_constrain_taxonomy_tenant_ownership.php';

    $migration->down();
    $columns = collect(Schema::getColumns('tenant_terms'))->keyBy('name');
    expect($columns['tenant_id']['nullable'])->toBeTrue()
        ->and(Schema::hasIndex('tenant_terms', 'terms_tenant_sibling_slug_unique'))->toBeFalse();

    $migration->up();
    expect(Schema::hasIndex('tenant_terms', 'terms_tenant_sibling_slug_unique'))->toBeTrue();
});
