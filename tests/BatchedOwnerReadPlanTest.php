<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Services\TaxonomyAssignments;
use Nvl\Taxonomy\Services\TaxonomyReader;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Taxonomy\Tests\TestCase;

uses(TestCase::class);

it('reads a batch of owners through the attachment indexes instead of scanning the attachments table', function (): void {
    $driver = DB::connection()->getDriverName();
    if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
        $this->markTestSkipped('Query plans are asserted on PostgreSQL and MySQL/MariaDB.');
    }

    $owners = [Post::create(['title' => 'First']), Post::create(['title' => 'Second'])];
    foreach ($owners as $owner) {
        app(TaxonomyAssignments::class)->sync($owner, 'tag', ['indexed'], SystemActor::Background);
    }
    $termId = Termable::query()->value('term_id');
    $termables = TaxonomyTables::get(TaxonomyTables::Termables);
    foreach (array_chunk(range(1, 3000), 500) as $chunk) {
        DB::table($termables)->insert(array_map(static fn (int $number): array => [
            'id' => (string) Str::uuid(), 'term_id' => $termId, 'termable_type' => 'other_owner', 'termable_id' => (string) (100000 + $number),
            'taxonomy' => 'tag', 'position' => 0, 'created_at' => now(), 'updated_at' => now(),
        ], $chunk));
    }
    DB::statement($driver === 'pgsql' ? "ANALYZE {$termables}" : "ANALYZE TABLE {$termables}");

    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $result = app(TaxonomyReader::class)->forOwners($owners, ['tag'], SystemActor::Background, 'en');
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }
    $reads = array_values(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], $termables)));

    expect($result['order'])->toHaveCount(2)
        ->and($reads)->not->toBeEmpty();

    foreach ($reads as $read) {
        if ($driver === 'pgsql') {
            $plan = implode("\n", array_map(static fn (object $row): string => (string) current((array) $row), DB::select('EXPLAIN '.$read['query'], $read['bindings'])));

            expect($plan)->not->toContain("Seq Scan on {$termables}");

            continue;
        }

        foreach (DB::select('EXPLAIN FORMAT=TRADITIONAL '.$read['query'], $read['bindings']) as $row) {
            if ($row->table === $termables) {
                expect($row->type)->not->toBe('ALL', json_encode($row, JSON_THROW_ON_ERROR))
                    ->and($row->key)->not->toBeNull();
            }
        }
    }
});
