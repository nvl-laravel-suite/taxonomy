<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Compatibility;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;

/** Preserves the guarded historical vocabulary-subclass contract outside the native ownership graph. @internal */
final readonly class LegacyTaxonomyBoundary implements OwnershipBoundary
{
    /** Retain only the explicitly temporary compatibility authority. */
    public function __construct(private TenantBoundary $boundary) {}

    /** Preserve registered vocabulary subclasses and canonical legacy aliases.
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function query(Builder $query, string $resource): Builder
    {
        return $this->boundary->query($query, $resource);
    }

    /** Require the original registered source and persisted downgrade checks. */
    public function assertRecord(Model $record, string $resource): void
    {
        $this->boundary->assertRecord($record, $resource);
    }

    /** @return array<string, int|string|null> */
    public function attributes(string $resource): array
    {
        return $this->boundary->attributes($resource);
    }

    /** Preserve only an identity admitted by the original authority. */
    public function key(string $resource, string $identity): string
    {
        return $this->boundary->key($resource, $identity);
    }
}
