<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Ownership\TaxonomyBoundary;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

/**
 * Atomic ordered term assignment operations for registered owners.
 *
 * @api
 */
final readonly class TaxonomyAssignments
{
    public function __construct(
        private TermAttachmentWriter $attachments,
        private TaxonomyBoundary $boundary,
        private TaxonomyActorPolicy $actors,
        private TaxonomyOwnerRegistry $owners,
    ) {}

    /** @param list<Term|string> $terms */
    public function attach(Model $owner, string $taxonomy, array $terms, Authenticatable|SystemActor $actor): void
    {
        $this->mutate($owner, $taxonomy, $actor, fn (Model $canonical, Authenticatable|SystemActor $current) => $this->attachments->append($canonical, $taxonomy, $terms, $current));
    }

    /** @param list<Term|string> $terms */
    public function detach(Model $owner, string $taxonomy, array $terms, Authenticatable|SystemActor $actor): int
    {
        $deleted = $this->mutate($owner, $taxonomy, $actor, fn (Model $canonical, Authenticatable|SystemActor $current): int => $this->attachments->detach($canonical, $taxonomy, $terms, $current));

        return $deleted;
    }

    /** @param list<Term|string> $terms */
    public function sync(Model $owner, string $taxonomy, array $terms, Authenticatable|SystemActor $actor): void
    {
        $this->mutate($owner, $taxonomy, $actor, fn (Model $canonical, Authenticatable|SystemActor $current) => $this->attachments->sync($canonical, $taxonomy, $terms, $current));
    }

    /**
     * @template T
     *
     * @param  \Closure(Model, Authenticatable|SystemActor):T  $operation
     * @return T
     */
    private function mutate(Model $owner, string $taxonomy, Authenticatable|SystemActor $actor, \Closure $operation): mixed
    {
        $connection = TermModelRegistry::newInstance()->getConnectionName();
        $lock = TaxonomyConfiguration::lock(
            TaxonomyConfiguration::attachmentLockName($this->boundary, $owner, $taxonomy),
            TaxonomyConfiguration::lockSeconds(),
        );

        return $lock->block(
            TaxonomyConfiguration::lockWaitSeconds(),
            fn (): mixed => DB::connection($connection)->transaction(function () use ($owner, $taxonomy, $actor, $operation): mixed {
                $canonical = $this->owners->resolve($owner, lock: true);
                $current = $this->actors->authorize($actor, 'assign', $canonical, $taxonomy);

                return $operation($canonical, $current);
            }, TaxonomyConfiguration::transactionAttempts()),
        );
    }
}
