<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Enums;

/**
 * Identifies the write path whose resulting membership set a policy reviews.
 *
 * @api
 */
enum TaxonomyMembershipOperation: string
{
    case Attach = 'attach';
    case Detach = 'detach';
    case Sync = 'sync';
    case Reconcile = 'reconcile';
    case Merge = 'merge';
    case Delete = 'delete';
    case Reorder = 'reorder';

    /** Manual and structural operations require Apply; only reconciliation may shadow. */
    public function isManual(): bool
    {
        return $this !== self::Reconcile;
    }
}
