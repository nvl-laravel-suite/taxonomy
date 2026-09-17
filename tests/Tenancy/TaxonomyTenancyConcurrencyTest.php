<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Nvl\Taxonomy\Actions\MoveTermAction;
use Nvl\Taxonomy\Tests\Fixtures\TaxonomyTenancyScenario;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\TenantId;

it('creates identical roots concurrently in independent tenant partitions', function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)
        || ! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid') || ! function_exists('pcntl_exec')) {
        $this->markTestSkipped('The Taxonomy race requires PostgreSQL/MySQL and pcntl.');
    }

    $scenario = TaxonomyTenancyScenario::install();
    $childrenByTenant = [
        $scenario::A => $scenario->term($scenario::A, 'race-child-a', 'category'),
        $scenario::B => $scenario->term($scenario::B, 'race-child-b', 'category'),
    ];
    $gate = tempnam(sys_get_temp_dir(), 'taxonomy-tenant-gate-');
    $results = [tempnam(sys_get_temp_dir(), 'taxonomy-a-'), tempnam(sys_get_temp_dir(), 'taxonomy-b-')];
    if (! is_string($gate) || in_array(false, $results, true)) {
        throw new RuntimeException('The Taxonomy race could not allocate IPC files.');
    }
    /** @var list<string> $results */
    $children = [];

    try {
        foreach ([$scenario::A, $scenario::B] as $index => $tenant) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('The Taxonomy race could not fork.');
            }
            if ($pid > 0) {
                $children[] = $pid;

                continue;
            }
            try {
                DB::purge();
                Container::getInstance()->forgetScopedInstances();
                while (file_get_contents($gate) !== 'go') {
                    usleep(10_000);
                }
                $result = app(TenantRunner::class)->run(new TenantId($tenant), function () use ($childrenByTenant, $scenario, $tenant): array {
                    $root = $scenario->term($tenant, 'race-root', 'category');
                    $child = $childrenByTenant[$tenant];
                    $moved = app(MoveTermAction::class)->execute($child, $root->id, 0, $child->revision);

                    return ['ok' => true, 'id' => $root->id, 'parent_id' => $moved->parent_id];
                });
            } catch (Throwable $exception) {
                $result = ['ok' => false, 'message' => $exception->getMessage()];
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
        expect($decoded[0]['ok'])->toBeTrue(json_encode($decoded[0], JSON_THROW_ON_ERROR))
            ->and($decoded[1]['ok'])->toBeTrue(json_encode($decoded[1], JSON_THROW_ON_ERROR))
            ->and($decoded[0]['id'])->not->toBe($decoded[1]['id'])
            ->and($decoded[0]['parent_id'])->toBe($decoded[0]['id'])
            ->and($decoded[1]['parent_id'])->toBe($decoded[1]['id']);
    } finally {
        foreach ($children as $child) {
            $status = 0;
            pcntl_waitpid($child, $status, WNOHANG);
        }
        foreach ([$gate, ...$results] as $path) {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }
});
