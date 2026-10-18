<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Concerns;

use Nvl\Taxonomy\Ownership\TaxonomyOwnership;

/** Prevents persisted Taxonomy ownership from changing through model writes. */
trait GuardsTenantOwnership
{
    /** Register immutable ownership enforcement for updates. */
    protected static function bootGuardsTenantOwnership(): void
    {
        static::updating(static function (self $model): void {
            if (array_key_exists('tenant_id', $model->getAttributes()) && $model->isDirty('tenant_id')) {
                throw TaxonomyOwnership::violation('Persisted Taxonomy ownership is immutable.');
            }
        });
    }
}
