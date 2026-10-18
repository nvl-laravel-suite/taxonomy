<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nvl\Taxonomy\Contracts\SyncTermAttachmentsContract;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Ownership\TaxonomyBoundary as OwnershipBoundary;
use Nvl\Taxonomy\Services\TermAttachmentWriter;
use Nvl\Taxonomy\Services\TermModelRegistry;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

/**
 * Atomically replaces one owner's ordered attachments for a vocabulary.
 *
 * @api
 */
final readonly class SyncTermAttachmentsAction implements SyncTermAttachmentsContract
{
    /**
     * Create the attachment synchronization action.
     */
    public function __construct(
        private TermAttachmentWriter $attachments,
        private OwnershipBoundary $boundary,
    ) {}

    /**
     * Replace one persisted owner's ordered vocabulary set.
     *
     * @param  list<Term|string>  $terms
     */
    public function execute(Model $owner, string $taxonomy, array $terms): void
    {
        $connection = TermModelRegistry::newInstance()->getConnectionName();
        $lock = TaxonomyConfiguration::lock(
            TaxonomyConfiguration::attachmentLockName($this->boundary, $owner, $taxonomy),
            TaxonomyConfiguration::lockSeconds(),
        );

        $lock->block(
            TaxonomyConfiguration::lockWaitSeconds(),
            fn () => DB::connection($connection)->transaction(
                fn () => $this->attachments->sync($owner, $taxonomy, $terms),
                TaxonomyConfiguration::transactionAttempts(),
            ),
        );
    }
}
