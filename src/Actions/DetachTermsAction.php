<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TermAttachmentWriter;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

/**
 * Removes selected terms or all terms from one vocabulary attachment set.
 */
final readonly class DetachTermsAction
{
    /**
     * Create the attachment removal action.
     */
    public function __construct(private TermAttachmentWriter $attachments) {}

    /**
     * Remove selected terms, or every term when none are supplied.
     *
     * @param  list<Term|string>  $terms
     */
    public function execute(Model $owner, string $taxonomy, array $terms = []): int
    {
        $connection = (new Term)->getConnectionName();
        $lock = Cache::lock(
            TaxonomyConfiguration::attachmentLockName($owner, $taxonomy),
            TaxonomyConfiguration::lockSeconds(),
        );

        $deleted = $lock->block(
            TaxonomyConfiguration::lockWaitSeconds(),
            fn (): int => DB::connection($connection)->transaction(
                fn (): int => $this->attachments->detach($owner, $taxonomy, $terms),
                TaxonomyConfiguration::transactionAttempts(),
            ),
        );

        if (! is_int($deleted)) {
            throw new LogicException('Taxonomy attachment deletion returned an invalid count.');
        }

        return $deleted;
    }
}
