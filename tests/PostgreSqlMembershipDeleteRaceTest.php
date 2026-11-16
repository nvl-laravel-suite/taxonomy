<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Contracts\TaxonomyMembershipPolicy;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Enums\TaxonomyMembershipMode;
use Nvl\Taxonomy\Models\Tag;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Services\TaxonomyAssignments;
use Nvl\Taxonomy\Support\TaxonomyDefinition;
use Nvl\Taxonomy\Support\TaxonomyMembershipProposal;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Taxonomy\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

it('finishes a manual sync racing a reviewed term delete with a consistent state', function (string $holder, string $waiter, bool $syncKeepsTerm): void {
    if (getenv('NVL_OWNERSHIP_TEST_DRIVER') !== 'pgsql' || DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires the isolated committed PostgreSQL package database.');
    }

    app()->instance(TaxonomyMembershipPolicy::class, new class implements TaxonomyMembershipPolicy
    {
        public function mode(Model $owner, TaxonomyDefinition $taxonomy, Authenticatable|SystemActor $actor, TaxonomyMembershipProposal $proposal): TaxonomyMembershipMode
        {
            return TaxonomyMembershipMode::Apply;
        }
    });
    $post = Post::create(['title' => 'Raced owner']);
    $other = Post::create(['title' => 'Attached owner']);
    app(TaxonomyAssignments::class)->sync($post, 'tag', ['keep'], SystemActor::Background);
    app(TaxonomyAssignments::class)->sync($other, 'tag', ['race-term'], SystemActor::Background);
    $term = Tag::query()->where('slug', 'race-term')->sole();
    // Publish the fixture to the worker connections; the rows are removed below.
    DB::commit();
    DB::purge();
    $connection = DB::connection();
    $source = $connection->getConfig();

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
        foreach ([$holder => true, $waiter => false] as $role => $holds) {
            $worker = new Process([
                PHP_BINARY,
                __DIR__.'/Fixtures/membership-race-worker.php',
                json_encode($source, JSON_THROW_ON_ERROR),
                json_encode(['directory' => $directory, 'role' => $role, 'holds' => $holds, 'owner' => $post->getKey(), 'term' => $term->id, 'revision' => $term->revision], JSON_THROW_ON_ERROR),
            ], env: ['XDEBUG_MODE' => 'off'], timeout: 40);
            $worker->start();
            $workers[$role] = $worker;
        }
        $await($directory.'/ready-'.$holder);
        $waiting = $await($directory.'/ready-'.$waiter);
        touch($directory.'/go');
        $holding = $await($directory.'/holding');
        expect($holding['pid'])->not->toBe($waiting['pid']);

        // The holder is parked inside its policy review with its locks held; the waiter must queue behind them.
        touch($directory.'/go-waiter');
        $blocked = false;
        $deadline = microtime(true) + 15;
        while (! $blocked && microtime(true) < $deadline) {
            $blockers = $connection->selectOne('select pg_blocking_pids(?)::text as pids', [$waiting['pid']])->pids;
            $blocked = in_array($holding['pid'], array_map(intval(...), explode(',', trim((string) $blockers, '{}'))), true);
            usleep(20000);
        }
        expect($blocked)->toBeTrue("The {$waiter} worker never waited on the {$holder} worker's locks.");
        touch($directory.'/release');

        $outcomes = [];
        foreach ($workers as $role => $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $outcomes[$role] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        $raced = Termable::query()->where('termable_id', (string) $post->getKey())->with('term')->get()
            ->map(static fn (Termable $attachment): string => $attachment->term->slug)->sort()->values()->all();

        expect($outcomes['race-sync']['status'])->toBe('synced')
            ->and($outcomes['race-delete']['status'])->toBe('deleted')
            ->and(Tag::query()->whereKey($term->id)->exists())->toBeFalse()
            ->and(Termable::query()->where('term_id', $term->id)->exists())->toBeFalse()
            ->and(Termable::query()->where('termable_id', (string) $other->getKey())->exists())->toBeFalse()
            ->and($raced)->toBe($syncKeepsTerm ? ['race-term'] : [])
            ->and(Termable::query()->whereDoesntHave('term')->exists())->toBeFalse();
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
})->with([
    // The sync re-creates the open-vocabulary term after the delete commits.
    'delete holds the term lock' => ['race-delete', 'race-sync', true],
    // The delete then reviews and detaches the attachment the sync committed.
    'sync holds the owner and term locks' => ['race-sync', 'race-delete', false],
]);
