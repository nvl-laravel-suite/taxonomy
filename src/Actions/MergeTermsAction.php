<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Actions;

use Illuminate\Support\Facades\DB;
use LogicException;
use Nvl\Taxonomy\Enums\TermChangeOperation;
use Nvl\Taxonomy\Events\TermChanged;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Services\TermMergeValidator;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

/**
 * Merges a source term into a destination without duplicate attachments.
 */
final readonly class MergeTermsAction
{
    /**
     * Create the term merge action.
     */
    public function __construct(private TermMergeValidator $validator) {}

    /**
     * Merge a source term into a destination at explicit optimistic revisions.
     */
    public function execute(
        Term|string $source,
        Term|string $destination,
        int $expectedSourceRevision,
        int $expectedDestinationRevision,
    ): Term {
        $sourceId = $source instanceof Term ? $source->id : $source;
        $destinationId = $destination instanceof Term ? $destination->id : $destination;
        $connection = $this->connectionFor($source, $destination);

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

            $table = (new Termable)->getTable();
            $database = DB::connection($connection);
            $destinationAttachments = $database
                ->table($table)
                ->where('term_id', $destination->id)
                ->lockForUpdate()
                ->get(['termable_type', 'termable_id']);
            $destinationAttachmentKeys = [];

            foreach ($destinationAttachments as $attachment) {
                $destinationAttachmentKeys[$this->attachmentKey($attachment)] = true;
            }

            $database->table($table)
                ->where('term_id', $source->id)
                ->lockForUpdate()
                ->orderBy('id')
                ->chunkById(250, function ($attachments) use (
                    $database,
                    $table,
                    $destination,
                    $destinationAttachmentKeys,
                ): void {
                    $duplicates = [];
                    $transferable = [];

                    foreach ($attachments as $attachment) {
                        $key = $this->attachmentKey($attachment);
                        $attachmentId = $this->attachmentId($attachment);

                        if (isset($destinationAttachmentKeys[$key])) {
                            $duplicates[] = $attachmentId;
                        } else {
                            $transferable[] = $attachmentId;
                        }
                    }

                    if ($duplicates !== []) {
                        $database->table($table)->whereIn('id', $duplicates)->delete();
                    }

                    if ($transferable !== []) {
                        $database->table($table)->whereIn('id', $transferable)->update([
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

    private function connectionFor(Term|string $source, Term|string $destination): ?string
    {
        if ($source instanceof Term) {
            return $source->getConnectionName();
        }

        if ($destination instanceof Term) {
            return $destination->getConnectionName();
        }

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
}
