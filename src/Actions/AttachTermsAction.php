<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nvl\Taxonomy\Contracts\AttachTermsContract;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Ownership\TaxonomyBoundary as OwnershipBoundary;
use Nvl\Taxonomy\Services\TermAttachmentWriter;
use Nvl\Taxonomy\Services\TermModelRegistry;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

/**
 * Appends terms while preserving existing attachment order.
 *
 * @api
 */
final readonly class AttachTermsAction implements AttachTermsContract
{
    /**
     * Create the attachment append action.
     */
    public function __construct(
        private TermAttachmentWriter $attachments,
        private OwnershipBoundary $boundary,
    ) {}

    /**
     * Append unique terms to one persisted owner's ordered vocabulary set.
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
                fn () => $this->attachments->append($owner, $taxonomy, $terms),
                TaxonomyConfiguration::transactionAttempts(),
            ),
        );
    }
}
