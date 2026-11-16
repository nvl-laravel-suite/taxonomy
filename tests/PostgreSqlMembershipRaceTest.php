<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Enums\TaxonomyMembershipMode;
use Nvl\Taxonomy\Models\MembershipMatch;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Services\TaxonomyAssignments;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Taxonomy\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

it('serializes a reconcile and a manual sync of one owner across independent PostgreSQL processes', function (): void {
    if (getenv('NVL_OWNERSHIP_TEST_DRIVER') !== 'pgsql' || DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires the isolated committed PostgreSQL package database.');
    }

    $post = Post::create(['title' => 'Raced owner']);
    app(TaxonomyAssignments::class)->sync($post, 'tag', ['existing'], SystemActor::Background);
    // Publish the fixture to the worker connections; the rows are removed below.
    DB::commit();
    DB::purge();
    $connection = DB::connection();
    $source = $connection->getConfig();
    $parent = (array) $connection->selectOne('select current_database() as database, pg_backend_pid() as pid');

    $directory = sys_get_temp_dir().'/nvl-taxonomy-membership-race-'.bin2hex(random_bytes(8));
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Cannot create the membership race barrier directory.');
    }
    $workers = [];
    $await = static function (string $path) use (&$workers): array {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            foreach ($workers as $worker) {
                $worker->checkTimeout();
                if (! $worker->isRunning()) {
                    throw new RuntimeException('A membership race worker exited early with code ['.$worker->getExitCode().']: '.$worker->getErrorOutput().$worker->getOutput());
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("The membership race barrier [{$path}] timed out.");
            }
            usleep(10000);
            clearstatcache();
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    };

    try {
        foreach (['reconcile', 'sync'] as $role) {
            $worker = new Process([
                PHP_BINARY,
                __DIR__.'/Fixtures/membership-race-worker.php',
                json_encode($source, JSON_THROW_ON_ERROR),
                json_encode(['directory' => $directory, 'role' => $role, 'owner' => $post->getKey()], JSON_THROW_ON_ERROR),
            ], env: ['XDEBUG_MODE' => 'off'], timeout: 40);
            $worker->start();
            $workers[$role] = $worker;
        }

        $holder = $await($directory.'/holding');
        $waiter = $await($directory.'/ready-sync');
        expect($holder['database'])->toBe($parent['database'])
            ->and($waiter['database'])->toBe($parent['database'])
            ->and(array_unique([(int) $parent['pid'], $holder['pid'], $waiter['pid']]))->toHaveCount(3);

        touch($directory.'/go');
        $blocked = false;
        $deadline = microtime(true) + 15;
        while (! $blocked && microtime(true) < $deadline) {
            $blockers = $connection->selectOne('select pg_blocking_pids(?)::text as pids', [$waiter['pid']])->pids;
            $blocked = in_array($holder['pid'], array_map(intval(...), explode(',', trim((string) $blockers, '{}'))), true);
            usleep(20000);
        }
        expect($blocked)->toBeTrue('The manual sync never waited on the reconciling owner lock.');
        touch($directory.'/release');

        $outcomes = [];
        foreach ($workers as $role => $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $outcomes[$role] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        $match = MembershipMatch::query()->sole();
        $attachments = Termable::query()->where('termable_id', (string) $post->getKey())->with('term')->get();

        expect($outcomes['reconcile']['mode'])->toBe(TaxonomyMembershipMode::Apply->value)
            ->and($outcomes['sync']['status'])->toBe('synced')
            ->and($match->source)->toBe('shopify')
            ->and($match->proposal)->toBe(['ruled-a', 'ruled-b'])
            ->and($match->mode)->toBe(TaxonomyMembershipMode::Apply)
            ->and($attachments->map(static fn (Termable $attachment): array => [$attachment->term->slug, $attachment->membership_source])->all())
            ->toBe([['manual', null]]);
    } finally {
        foreach ($workers as $worker) {
            $worker->stop();
        }
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
        foreach ([TaxonomyTables::MembershipMatches, TaxonomyTables::Termables, TaxonomyTables::I18n, TaxonomyTables::Terms] as $table) {
            DB::table(TaxonomyTables::get($table))->delete();
        }
        DB::table('taxonomy_posts')->delete();
    }
});
