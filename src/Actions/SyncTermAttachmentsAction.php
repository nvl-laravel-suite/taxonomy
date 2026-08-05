<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TermAttachmentWriter;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

/**
 * Atomically replaces one owner's ordered attachments for a vocabulary.
 */
final readonly class SyncTermAttachmentsAction
{
    /**
     * Create the attachment synchronization action.
     */
    public function __construct(
        private TermAttachmentWriter $attachments,
    ) {}

    /**
     * Replace one persisted owner's ordered vocabulary set.
     *
     * @param  list<Term|string>  $terms
     */
    public function execute(Model $owner, string $taxonomy, array $terms): void
    {
        $connection = (new Term)->getConnectionName();
        $lock = Cache::lock(
            TaxonomyConfiguration::attachmentLockName($owner, $taxonomy),
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
