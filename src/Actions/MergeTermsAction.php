<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Nvl\Taxonomy\Enums\TermChangeOperation;
use Nvl\Taxonomy\Events\TermChanged;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Taxonomy\Services\TermMergeValidator;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

/**
 * Merges a source term into a destination without duplicate attachments.
 */
final readonly class MergeTermsAction
{
    /**
     * Create the term merge action.
     */
    public function __construct(
        private TermMergeValidator $validator,
        private TaxonomyOwnerRegistry $owners,
    ) {}

    /**
     * Merge a source term into a destination at explicit optimistic revisions.
     */
    public function execute(
        Term|string $source,
        Term|string $destination,
        int $expectedSourceRevision,
        int $expectedDestinationRevision,
    ): Term {
        $sourceId = $source instanceof Term
            ? $source->getRawOriginal($source->getKeyName())
            : $source;
        $destinationId = $destination instanceof Term
            ? $destination->getRawOriginal($destination->getKeyName())
            : $destination;

        if (! is_string($sourceId) || ! is_string($destinationId)) {
            throw new InvalidArgumentException('Canonical merge term identifiers are required.');
        }
        $connection = $this->connectionFor();

        return DB::connection($connection)->transaction(function () use (
            $sourceId,
            $destinationId,
            $expectedSourceRevision,
            $expectedDestinationRevision,
            $connection,
        ): Term {
            $context = $this->validator->validate(
                $sourceId,
                $destinationId,
                $expectedSourceRevision,
                $expectedDestinationRevision,
            );
            $source = $context->source;
            $destination = $context->destination;
            $tenant = $source->getRawOriginal('tenant_id');
            if (config('tenancy.enabled') === true && ! is_string($tenant)) {
                throw new TenantBoundaryViolation('A merged Taxonomy term lacks tenant ownership.');
            }

            $table = (new Termable)->getTable();
            $database = DB::connection($connection);
            $destinationAttachments = $database
                ->table($table)
                ->where('term_id', $destination->id)
                ->when(is_string($tenant), static fn ($query) => $query->where('tenant_id', $tenant))
                ->lockForUpdate()
                ->get(['termable_type', 'termable_id']);
            $destinationAttachmentKeys = [];

            foreach ($destinationAttachments as $attachment) {
                $destinationAttachmentKeys[$this->attachmentKey($attachment)] = true;
            }

            $database->table($table)
                ->where('term_id', $source->id)
                ->when(is_string($tenant), static fn ($query) => $query->where('tenant_id', $tenant))
                ->lockForUpdate()
                ->orderBy('id')
                ->chunkById(250, function ($attachments) use (
                    $database,
                    $table,
                    $destination,
                    $destinationAttachmentKeys,
                    $tenant,
                ): void {
                    $duplicates = [];
                    $transferable = [];

                    foreach ($attachments as $attachment) {
                        $this->assertAttachmentOwner($attachment, $tenant);
                        $key = $this->attachmentKey($attachment);
                        $attachmentId = $this->attachmentId($attachment);

                        if (isset($destinationAttachmentKeys[$key])) {
                            $duplicates[] = $attachmentId;
                        } else {
                            $transferable[] = $attachmentId;
                        }
                    }

                    if ($duplicates !== []) {
                        $database->table($table)->whereIn('id', $duplicates)
                            ->when(is_string($tenant), static fn ($query) => $query->where('tenant_id', $tenant))
                            ->delete();
                    }

                    if ($transferable !== []) {
                        $database->table($table)->whereIn('id', $transferable)
                            ->when(is_string($tenant), static fn ($query) => $query->where('tenant_id', $tenant))
                            ->update([
                            'term_id' => $destination->id,
                            'updated_at' => now(),
                        ]);
                    }
                });

            foreach ($context->children as $child) {
                $child->parent_id = $destination->id;
                $child->save();
                TermChanged::dispatch(
                    $child->id,
                    $child->taxonomy,
                    TermChangeOperation::Moved,
                    $child->revision,
                );
            }

            $destination->revision++;
            $destination->save();
            TermChanged::dispatch(
                $destination->id,
                $destination->taxonomy,
                TermChangeOperation::Merged,
                $destination->revision,
            );
            TermChanged::dispatch(
                $source->id,
                $source->taxonomy,
                TermChangeOperation::Deleted,
                $source->revision,
            );
            $source->delete();

            return $destination->refresh()->load('translations');
        }, TaxonomyConfiguration::transactionAttempts());
    }

    private function connectionFor(): ?string
    {
        return (new Term)->getConnectionName();
    }

    private function attachmentKey(object $attachment): string
    {
        $attributes = get_object_vars($attachment);
        $type = $attributes['termable_type'] ?? null;
        $identifier = $attributes['termable_id'] ?? null;

        if (! is_string($type) || (! is_string($identifier) && ! is_int($identifier))) {
            throw new LogicException('A taxonomy attachment contains an invalid owner identifier.');
        }

        return $type.'|'.$identifier;
    }

    private function attachmentId(object $attachment): string
    {
        $identifier = get_object_vars($attachment)['id'] ?? null;

        if (! is_string($identifier)) {
            throw new LogicException('A taxonomy attachment contains an invalid row identifier.');
        }

        return $identifier;
    }

    /** Require a transferred attachment to resolve to a canonical owner in this tenant. */
    private function assertAttachmentOwner(object $attachment, mixed $tenant): void
    {
        if (! is_string($tenant)) {
            return;
        }
        $attributes = get_object_vars($attachment);
        $type = $attributes['termable_type'] ?? null;
        $identifier = $attributes['termable_id'] ?? null;
        $modelClass = is_string($type) ? ($this->owners->all()[$type] ?? null) : null;
        if (! is_string($modelClass) || (! is_string($identifier) && ! is_int($identifier))) {
            throw new TenantBoundaryViolation('A Taxonomy attachment has an unknown canonical owner.');
        }
        $owner = (new $modelClass)->newQueryWithoutScopes()->whereKey($identifier)->first();
        if (! $owner instanceof Model
            || $this->owners->resolve($owner, lock: true)->getRawOriginal('tenant_id') !== $tenant) {
            throw new TenantBoundaryViolation('A Taxonomy attachment belongs to another tenant.');
        }
    }
}
