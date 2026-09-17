<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Nvl\Taxonomy\Actions\DeleteTermAction;
use Nvl\Taxonomy\Enums\DeleteTermStrategy;
use Nvl\Taxonomy\Exceptions\StaleTermVersionException;
use Nvl\Taxonomy\Exceptions\UnsafeTermDeletionException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\TenantId;

/**
 * Prunes unattached leaf terms in locked chunks.
 */
final class PruneOrphansCommand extends Command
{
    protected $signature = 'nvl:taxonomy:prune
        {taxonomy? : Optional vocabulary key}
        {--tenant= : Canonical tenant UUID when tenancy is enabled}
        {--dry-run : Report without deleting}
        {--include-closed : Also prune canonical terms from closed vocabularies}
        {--force : Skip confirmation}
        {--chunk=200 : Terms deleted per chunk}';

    protected $description = 'Prune unattached taxonomy leaf terms';

    /**
     * Safely prune eligible orphan leaves in bounded chunks.
     */
    public function handle(
        DeleteTermAction $delete,
        TaxonomyRegistry $taxonomies,
        TenantRunner $runner,
        TenantBoundary $boundary,
    ): int {
        $tenant = $this->option('tenant');
        if (config('tenancy.enabled') === true) {
            if (! is_string($tenant) || $tenant === '') {
                $this->error('Enabled taxonomy maintenance requires --tenant.');

                return self::FAILURE;
            }

            return $runner->run(
                new TenantId($tenant),
                fn (): int => $this->handleForTenant($delete, $taxonomies, $boundary),
            );
        }

        return $this->handleForTenant($delete, $taxonomies, $boundary);
    }

    /** Prune the current tenant without mistaking foreign attachments for absence. */
    private function handleForTenant(
        DeleteTermAction $delete,
        TaxonomyRegistry $taxonomies,
        TenantBoundary $boundary,
    ): int {
        $query = Term::query()
            ->whereDoesntHave('attachments')
            ->whereDoesntHave('children');
        $taxonomy = $this->argument('taxonomy');

        if (is_string($taxonomy) && $taxonomy !== '') {
            $definition = $taxonomies->get($taxonomy);

            if (! $definition->open && ! (bool) $this->option('include-closed')) {
                $this->warn(
                    "Closed taxonomy [{$taxonomy}] is protected; use --include-closed explicitly.",
                );

                return self::SUCCESS;
            }

            $query->where('taxonomy', $taxonomy);
        } elseif (! (bool) $this->option('include-closed')) {
            $query->whereIn(
                'taxonomy',
                collect($taxonomies->all())
                    ->filter(static fn ($definition): bool => $definition->open)
                    ->keys()
                    ->all(),
            );
        }

        $count = $query->count();
        $this->info("{$count} orphan leaf terms match.");

        if ((bool) $this->option('dry-run') || $count === 0) {
            return self::SUCCESS;
        }

        if (! (bool) $this->option('force')
            && ! $this->confirm("Delete {$count} orphan leaf terms?")) {
            return self::FAILURE;
        }

        $lock = Cache::lock($boundary->key('taxonomy.terms', 'prune:'.(is_string($taxonomy) ? $taxonomy : '*')), 3600);

        if (! $lock->get()) {
            $this->error('Another taxonomy prune owns the process lock.');

            return self::FAILURE;
        }

        try {
            $skipped = 0;
            $query->orderBy('id')->chunkById(
                max(1, (int) $this->option('chunk')),
                function ($terms) use ($delete, &$skipped): void {
                    foreach ($terms as $term) {
                        try {
                            $delete->execute(
                                $term,
                                $term->revision,
                                DeleteTermStrategy::Restrict,
                            );
                        } catch (ModelNotFoundException|StaleTermVersionException|UnsafeTermDeletionException) {
                            $skipped++;
                        }
                    }
                },
            );

            if ($skipped > 0) {
                $this->warn("Skipped {$skipped} terms that changed during pruning.");
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
