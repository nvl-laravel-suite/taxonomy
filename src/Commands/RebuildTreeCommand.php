<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Commands;

use Illuminate\Console\Command;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Ownership\TaxonomyBoundary as OwnershipBoundary;
use Nvl\Taxonomy\Services\TaxonomyTerms;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

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
    public function handle(TaxonomyTerms $terms, OwnershipBoundary $boundary): int
    {
        return $this->handleForOwner($terms, $boundary);
    }

    /** Rebuild the current owner's tree under an owner-keyed process lock. */
    private function handleForOwner(TaxonomyTerms $terms, OwnershipBoundary $boundary): int
    {
        $taxonomy = $this->argument('taxonomy');
        $taxonomy = is_string($taxonomy) && $taxonomy !== '' ? $taxonomy : null;
        $changes = $terms->rebuild(SystemActor::Background, $taxonomy, dryRun: true);
        $this->info("{$changes} term positions require normalization.");

        if ((bool) $this->option('dry-run') || $changes === 0) {
            return self::SUCCESS;
        }

        $lockKey = 'nvl:taxonomy:rebuild:'.$boundary->key('taxonomy.terms', 'rebuild:'.($taxonomy ?? '*'));
        $lock = TaxonomyConfiguration::lock($lockKey, 3600);

        if (! $lock->get()) {
            $this->error('Another taxonomy rebuild owns the process lock.');

            return self::FAILURE;
        }

        try {
            $terms->rebuild(SystemActor::Background, $taxonomy);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
