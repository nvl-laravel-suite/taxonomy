<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Nvl\Taxonomy\Enums\TermChangeOperation;
use Nvl\Taxonomy\Events\TermChanged;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/**
 * Normalizes sibling positions independently inside every vocabulary tree.
 */
final readonly class RebuildTreeAction
{
    /**
     * Create the tree rebuild action.
     */
    public function __construct(private TaxonomyRegistry $taxonomies) {}

    /**
     * Normalize sibling positions or return the prospective change count.
     */
    public function execute(?string $taxonomy = null, bool $dryRun = false): int
    {
        $connection = (new Term)->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($taxonomy, $dryRun): int {
            $changes = 0;

            foreach ($this->terms($taxonomy, lock: ! $dryRun)
                ->groupBy(static fn (Term $term): string => $term->taxonomy.'|'.$term->parent_key) as $children) {
                foreach ($children->values() as $index => $child) {
                    if ($child->position === $index) {
                        continue;
                    }

                    $changes++;

                    if ($dryRun) {
                        continue;
                    }

                    $child->position = $index;
                    $child->save();
                    TermChanged::dispatch(
                        $child->id,
                        $child->taxonomy,
                        TermChangeOperation::Reordered,
                        $child->revision,
                    );
                }
            }

            return $changes;
        }, TaxonomyConfiguration::transactionAttempts());
    }

    /**
     * @return Collection<int, Term>
     */
    private function terms(?string $taxonomy, bool $lock = false): Collection
    {
        if ($taxonomy !== null && $taxonomy !== '') {
            $definitions = [$this->taxonomies->get($taxonomy)];
        } else {
            $definitions = array_values($this->taxonomies->all());
        }

        $terms = new Collection;

        foreach ($definitions as $definition) {
            $query = $definition->model::query()
                ->where('taxonomy', $definition->taxonomy);

            if ($lock) {
                $query->lockForUpdate();
            }

            $terms->push(...$query
                ->orderBy('parent_key')
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->all());
        }

        return $terms;
    }
}
