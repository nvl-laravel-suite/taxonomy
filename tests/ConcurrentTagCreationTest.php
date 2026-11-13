<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Models\Tag;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Services\TaxonomyAssignments;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Taxonomy\Tests\TestCase;

uses(TestCase::class);

it('ends two transactions creating the same new tag with one term and both syncs applied', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql'
        || ! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid') || ! function_exists('pcntl_exec')) {
        $this->markTestSkipped('The tag creation race requires PostgreSQL and pcntl.');
    }

    $posts = [Post::create(['title' => 'First tagger']), Post::create(['title' => 'Second tagger'])];
    $files = array_map(static fn (string $prefix): string|false => tempnam(sys_get_temp_dir(), $prefix), ['taxonomy-tag-gate-', 'taxonomy-tag-ready-a-', 'taxonomy-tag-ready-b-', 'taxonomy-tag-a-', 'taxonomy-tag-b-']);
    if (in_array(false, $files, true)) {
        throw new RuntimeException('The tag creation race could not allocate IPC files.');
    }
    /** @var list<string> $files */
    [$gate, $readyA, $readyB, $resultA, $resultB] = $files;
    $ready = [$readyA, $readyB];
    $results = [$resultA, $resultB];
    $children = [];

    // Publish the fixture owners to the forked connections; the rows are removed below.
    DB::commit();
    DB::purge();
    try {
        foreach ($posts as $index => $post) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('The tag creation race could not fork.');
            }
            if ($pid > 0) {
                $children[] = $pid;

                continue;
            }
            try {
                DB::purge();
                Container::getInstance()->forgetScopedInstances();
                $raced = false;
                Gate::define('taxonomy.create', static function (mixed $actor) use ($ready, $index, &$raced): bool {
                    file_put_contents($ready[$index], 'ready');
                    $deadline = microtime(true) + 10;
                    while (microtime(true) < $deadline && ! $raced) {
                        $raced = file_get_contents($ready[0]) === 'ready' && file_get_contents($ready[1]) === 'ready';
                        usleep(5_000);
                    }

                    return $actor === SystemActor::Background;
                });
                while (file_get_contents($gate) !== 'go') {
                    usleep(10_000);
                }
                app(TaxonomyAssignments::class)->sync($post, 'tag', ['race-tag'], SystemActor::Background);
                $result = ['ok' => true, 'raced' => $raced, 'terms' => Termable::query()->where('termable_id', (string) $post->id)->pluck('term_id')->all()];
            } catch (Throwable $exception) {
                $result = ['ok' => false, 'message' => $exception::class.': '.$exception->getMessage()];
            }
            file_put_contents($results[$index], json_encode($result, JSON_THROW_ON_ERROR));
            pcntl_exec('/usr/bin/true');
            exit(1);
        }
        file_put_contents($gate, 'go');
        foreach ($children as $child) {
            $status = 0;
            pcntl_waitpid($child, $status);
            expect(pcntl_wifexited($status))->toBeTrue()
                ->and(pcntl_wexitstatus($status))->toBe(0);
        }
        $decoded = array_map(static fn (string $path): array => json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR), $results);
        $terms = Tag::query()->where('slug', 'race-tag')->pluck('id')->all();

        expect($decoded[0]['ok'])->toBeTrue(json_encode($decoded[0], JSON_THROW_ON_ERROR))
            ->and($decoded[1]['ok'])->toBeTrue(json_encode($decoded[1], JSON_THROW_ON_ERROR))
            ->and($decoded[0]['raced'])->toBeTrue()
            ->and($decoded[1]['raced'])->toBeTrue()
            ->and($terms)->toHaveCount(1)
            ->and($decoded[0]['terms'])->toBe($terms)
            ->and($decoded[1]['terms'])->toBe($terms);
    } finally {
        foreach ($children as $child) {
            $status = 0;
            pcntl_waitpid($child, $status, WNOHANG);
        }
        foreach ($files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        foreach ([TaxonomyTables::Termables, TaxonomyTables::I18n, TaxonomyTables::Terms] as $table) {
            DB::table(TaxonomyTables::get($table))->delete();
        }
        DB::table('taxonomy_posts')->delete();
    }
});
