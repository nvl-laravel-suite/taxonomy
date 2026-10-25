<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Concerns;

use Nvl\Taxonomy\Ownership\TaxonomyOwnership;

/** Prevents persisted Taxonomy ownership from changing through model writes. */
trait GuardsOwnerScope
{
    /** Register immutable ownership enforcement for updates. */
    protected static function bootGuardsOwnerScope(): void
    {
        static::updating(static function (self $model): void {
            if (array_key_exists('owner_scope_key', $model->getAttributes()) && $model->isDirty('owner_scope_key')) {
                throw TaxonomyOwnership::violation('Persisted Taxonomy ownership is immutable.');
            }
        });
    }
}
