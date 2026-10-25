<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Ownership;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TermModelRegistry;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/**
 * Admits registered vocabulary subclasses against their canonical unowned storage.
 *
 * @internal
 */
final readonly class TaxonomyBoundary implements OwnershipBoundary
{
    public function __construct(private OwnershipBoundary $ownership, private TaxonomyRegistry $taxonomies) {}

    /** @template T of Model
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function query(Builder $query, string $resource): Builder
    {
        if ($resource === 'taxonomy.terms' && ! TaxonomyOwnership::declared()) {
            $this->assertRecord($query->getModel(), $resource);
            $base = $query->getQuery();
            if ($base->getConnection() !== $query->getModel()->getConnection()
                || $base->unions !== null || $base->from !== $query->getModel()->getTable()) {
                throw new OwnershipViolation('The taxonomy query differs from canonical storage.');
            }

            return $query;
        }

        return $this->ownership->query($query, $resource);
    }

    public function assertRecord(Model $record, string $resource): void
    {
        if ($resource === 'taxonomy.terms' && ! TaxonomyOwnership::declared() && $record instanceof Term) {
            $canonical = TermModelRegistry::newInstance();
            $registered = $record::class === $canonical::class;
            foreach ($this->taxonomies->all() as $definition) {
                $registered = $registered || $record::class === $definition->model;
            }
            if (! $registered || $record->getTable() !== $canonical->getTable()
                || $record->getConnection() !== $canonical->getConnection()) {
                throw new OwnershipViolation('The taxonomy term differs from registered canonical storage.');
            }
            $this->ownership->assertRecord($canonical, $resource);

            return;
        }

        $this->ownership->assertRecord($record, $resource);
    }

    /** @return array<string,int|string|null> */
    public function attributes(string $resource): array
    {
        return $this->ownership->attributes($resource);
    }

    public function key(string $resource, string $identity): string
    {
        return $this->ownership->key($resource, $identity);
    }
}
