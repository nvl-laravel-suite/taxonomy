<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Nvl\Taxonomy\Actions\RebuildTreeAction;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\TenantId;

/**
 * Normalizes sibling positions with a distributed process lock.
 */
final class RebuildTreeCommand extends Command
{
    protected $signature = 'nvl:taxonomy:rebuild
        {taxonomy? : Optional vocabulary key}
        {--tenant= : Canonical tenant UUID when tenancy is enabled}
        {--dry-run : Report without writing}';

    protected $description = 'Normalize taxonomy sibling positions';

    /**
     * Preview and optionally normalize sibling positions.
     */
    public function handle(RebuildTreeAction $rebuild, TenantRunner $runner, TenantBoundary $boundary): int
    {
        $tenant = $this->option('tenant');
        if (config('tenancy.enabled') === true) {
            if (! is_string($tenant) || $tenant === '') {
                $this->error('Enabled taxonomy maintenance requires --tenant.');

                return self::FAILURE;
            }

            return $runner->run(
                new TenantId($tenant),
                fn (): int => $this->handleForTenant($rebuild, $boundary),
            );
        }

        return $this->handleForTenant($rebuild, $boundary);
    }

    /** Rebuild the current tenant's tree under a tenant-keyed process lock. */
    private function handleForTenant(RebuildTreeAction $rebuild, TenantBoundary $boundary): int
    {
        $taxonomy = $this->argument('taxonomy');
        $taxonomy = is_string($taxonomy) && $taxonomy !== '' ? $taxonomy : null;
        $changes = $rebuild->execute($taxonomy, dryRun: true);
        $this->info("{$changes} term positions require normalization.");

        if ((bool) $this->option('dry-run') || $changes === 0) {
            return self::SUCCESS;
        }

        $lockKey = config('tenancy.enabled') === true
            ? $boundary->key('taxonomy.terms', 'rebuild:'.($taxonomy ?? '*'))
            : 'nvl:taxonomy:rebuild';
        $lock = Cache::lock($lockKey, 3600);

        if (! $lock->get()) {
            $this->error('Another taxonomy rebuild owns the process lock.');

            return self::FAILURE;
        }

        try {
            $rebuild->execute($taxonomy);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
