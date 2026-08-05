<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Nvl\Taxonomy\Actions\RebuildTreeAction;

/**
 * Normalizes sibling positions with a distributed process lock.
 */
final class RebuildTreeCommand extends Command
{
    protected $signature = 'nvl:taxonomy:rebuild
        {taxonomy? : Optional vocabulary key}
        {--dry-run : Report without writing}';

    protected $description = 'Normalize taxonomy sibling positions';

    /**
     * Preview and optionally normalize sibling positions.
     */
    public function handle(RebuildTreeAction $rebuild): int
    {
        $taxonomy = $this->argument('taxonomy');
        $taxonomy = is_string($taxonomy) && $taxonomy !== '' ? $taxonomy : null;
        $changes = $rebuild->execute($taxonomy, dryRun: true);
        $this->info("{$changes} term positions require normalization.");

        if ((bool) $this->option('dry-run') || $changes === 0) {
            return self::SUCCESS;
        }

        $lock = Cache::lock('nvl:taxonomy:rebuild', 3600);

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
