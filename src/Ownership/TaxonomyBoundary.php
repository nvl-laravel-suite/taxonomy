<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Ownership;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;

/**
 * Keeps legacy vocabulary subclasses inside their existing authority until an explicit canonical root is selected.
 *
 * @internal
 */
final readonly class TaxonomyBoundary implements OwnershipBoundary
{
    public function __construct(private OwnershipBoundary $ownership, private TenantBoundary $legacy) {}

    /** @template T of Model
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function query(Builder $query, string $resource): Builder
    {
        return TaxonomyOwnership::declared() ? $this->ownership->query($query, $resource) : $this->legacy->query($query, $resource);
    }

    public function assertRecord(Model $record, string $resource): void
    {
        if (TaxonomyOwnership::declared()) {
            $this->ownership->assertRecord($record, $resource);
        } else {
            $this->legacy->assertRecord($record, $resource);
        }
    }

    /** @return array<string,int|string|null> */
    public function attributes(string $resource): array
    {
        return TaxonomyOwnership::declared() ? $this->ownership->attributes($resource) : $this->legacy->attributes($resource);
    }

    public function key(string $resource, string $identity): string
    {
        return TaxonomyOwnership::declared() ? $this->ownership->key($resource, $identity) : $this->legacy->key($resource, $identity);
    }
}
