<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Taxonomy\Enums\DeleteTermStrategy;
use Nvl\Taxonomy\Enums\TermChangeOperation;
use Nvl\Taxonomy\Events\TermChanged;
use Nvl\Taxonomy\Exceptions\CircularHierarchyException;
use Nvl\Taxonomy\Exceptions\StaleTermVersionException;
use Nvl\Taxonomy\Exceptions\UnsafeTermDeletionException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Ownership\TaxonomyOwnership;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Translatable\Enums\TranslationSyncMode;

/**
 * Complete atomic term mutations for registered vocabularies.
 *
 * @api
 */
final readonly class TaxonomyTerms
{
    public function __construct(
        private TermWriter $writer,
        private TaxonomyRegistry $taxonomies,
        private TermModelResolver $terms,
        private TermHierarchy $hierarchy,
        private TermMergeValidator $validator,
        private TaxonomyOwnerRegistry $owners,
        private DomainEventDispatcher $domainEvents,
        private TaxonomyActorPolicy $actors,
    ) {}

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes, Authenticatable|SystemActor $actor): Term
    {
        $this->actors->authorize($actor, 'create');
        $taxonomy = $attributes['taxonomy'] ?? null;
        if (! is_string($taxonomy) || $taxonomy === '') {
            throw new InvalidArgumentException('A taxonomy identifier is required.');
        }
        $definition = $this->taxonomies->get($taxonomy);
        $connection = (new ($definition->model))->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($attributes): Term {
            $term = $this->writer->create($attributes);
            $this->domainEvents->dispatch(new TermChanged(
                $term->id,
                $term->taxonomy,
                TermChangeOperation::Created,
                $term->revision,
            ), $term->getConnection());

            return $term;
        }, TaxonomyConfiguration::transactionAttempts());
    }

    /** @param array<string,mixed> $attributes */
    public function update(
        Term|string $term,
        array $attributes,
        Authenticatable|SystemActor $actor,
        TranslationSyncMode $mode = TranslationSyncMode::Patch,
    ): Term {
        $this->actors->authorize($actor, 'update');
        $termId = $term instanceof Term
            ? $term->getRawOriginal($term->getKeyName())
            : $term;

        if (! is_string($termId)) {
            throw new InvalidArgumentException('A canonical taxonomy term identifier is required.');
        }
        $connection = TermModelRegistry::newInstance()->getConnectionName();

        return DB::connection($connection)->transaction(function () use (
            $termId,
            $attributes,
            $mode,
        ): Term {
            $resolvedTerm = $this->terms->lock($termId);
            $this->taxonomies->get($resolvedTerm->taxonomy);

            $updated = $this->writer->update($resolvedTerm, $attributes, $mode);
            $this->domainEvents->dispatch(new TermChanged(
                $updated->id,
                $updated->taxonomy,
                TermChangeOperation::Updated,
                $updated->revision,
            ), $updated->getConnection());

            return $updated;
        }, TaxonomyConfiguration::transactionAttempts());
    }

    public function delete(
        Term|string $term,
        int $expectedRevision,
        Authenticatable|SystemActor $actor,
        DeleteTermStrategy $strategy = DeleteTermStrategy::Restrict,
        ?string $reparentTo = null,
    ): bool {
        $this->actors->authorize($actor, 'delete');
        $connection = TermModelRegistry::newInstance()->getConnectionName();

        return DB::connection($connection)->transaction(function () use (
            $term,
            $expectedRevision,
            $strategy,
            $reparentTo,
        ): bool {
            $term = $this->terms->lock($term);

            if ($term->revision !== $expectedRevision) {
                throw StaleTermVersionException::forTerm($term->id);
            }

            $hasAttachments = $term->attachments()->exists();
            $hasChildren = $term->children()->exists();

            if ($strategy === DeleteTermStrategy::Restrict && ($hasAttachments || $hasChildren)) {
                throw UnsafeTermDeletionException::because(
                    'Attached terms or terms with children require an explicit deletion strategy.',
                );
            }

            if ($strategy === DeleteTermStrategy::Reparent) {
                if ($reparentTo === $term->id) {
                    throw new CircularHierarchyException(
                        'A deleted term cannot be its own reparenting destination.',
                    );
                }

                $children = $term->children()
                    ->lockForUpdate()
                    ->get();

                $this->hierarchy->validateMoves($children, $reparentTo);

                foreach ($children as $child) {
                    $child->parent_id = $reparentTo;
                    $child->save();
                    $this->domainEvents->dispatch(new TermChanged(
                        $child->id,
                        $child->taxonomy,
                        TermChangeOperation::Moved,
                        $child->revision,
                    ), $child->getConnection());
                }
            } elseif ($strategy === DeleteTermStrategy::Cascade) {
                foreach ($this->hierarchy->descendants($term)->reverse() as $descendant) {
                    $descendant->attachments()->delete();
                    $this->domainEvents->dispatch(new TermChanged(
                        $descendant->id,
                        $descendant->taxonomy,
                        TermChangeOperation::Deleted,
                        $descendant->revision,
                    ), $descendant->getConnection());
                    $descendant->delete();
                }
            } elseif ($hasChildren) {
                throw UnsafeTermDeletionException::because(
                    'The selected deletion strategy does not handle child terms.',
                );
            }

            if ($strategy !== DeleteTermStrategy::Restrict) {
                $term->attachments()->delete();
            }

            $this->domainEvents->dispatch(new TermChanged(
                $term->id,
                $term->taxonomy,
                TermChangeOperation::Deleted,
                $term->revision,
            ), $term->getConnection());

            return (bool) $term->delete();
        }, TaxonomyConfiguration::transactionAttempts());
    }

    public function move(
        Term|string $term,
        Term|string|null $parent,
        Authenticatable|SystemActor $actor,
        int $position,
        int $expectedRevision,
    ): Term {
        $this->actors->authorize($actor, 'move');
        $parentId = $parent instanceof Term ? $parent->getRawOriginal($parent->getKeyName()) : $parent;
        if ($parentId !== null && ! is_string($parentId)) {
            throw new InvalidArgumentException('A canonical parent identifier is required.');
        }
        if ($position < 0) {
            throw new InvalidArgumentException('Taxonomy term positions cannot be negative.');
        }

        $connection = TermModelRegistry::newInstance()->getConnectionName();

        return DB::connection($connection)->transaction(function () use (
            $term,
            $parentId,
            $position,
            $expectedRevision,
        ): Term {
            $term = $this->terms->lock($term);

            if ($term->revision !== $expectedRevision) {
                throw StaleTermVersionException::forTerm($term->id);
            }

            $this->hierarchy->validateMoves(new Collection([$term]), $parentId);

            $term->parent_id = $parentId;
            $term->position = $position;
            $term->save();
            $this->domainEvents->dispatch(new TermChanged(
                $term->id,
                $term->taxonomy,
                TermChangeOperation::Moved,
                $term->revision,
            ), $term->getConnection());

            return $term->refresh()->load('translations');
        }, TaxonomyConfiguration::transactionAttempts());
    }

    public function merge(
        Term|string $source,
        Term|string $destination,
        Authenticatable|SystemActor $actor,
        int $expectedSourceRevision,
        int $expectedDestinationRevision,
    ): Term {
        $this->actors->authorize($actor, 'merge');
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
            $ownerScope = TaxonomyOwnership::declared() ? $source->getRawOriginal('owner_scope_key') : null;
            if (TaxonomyOwnership::declared() && ! is_string($ownerScope)) {
                throw TaxonomyOwnership::violation('A merged Taxonomy term lacks owner scope.');
            }

            $table = (new Termable)->getTable();
            $database = DB::connection($connection);
            $destinationAttachments = $database
                ->table($table)
                ->where('term_id', $destination->id)
                ->when(is_string($ownerScope), static fn ($query) => $query->where('owner_scope_key', $ownerScope))
                ->lockForUpdate()
                ->get(['termable_type', 'termable_id']);
            $destinationAttachmentKeys = [];

            foreach ($destinationAttachments as $attachment) {
                $destinationAttachmentKeys[$this->attachmentKey($attachment)] = true;
            }

            $database->table($table)
                ->where('term_id', $source->id)
                ->when(is_string($ownerScope), static fn ($query) => $query->where('owner_scope_key', $ownerScope))
                ->lockForUpdate()
                ->orderBy('id')
                ->chunkById(250, function ($attachments) use (
                    $database,
                    $table,
                    $destination,
                    $destinationAttachmentKeys,
                    $ownerScope,
                ): void {
                    $duplicates = [];
                    $transferable = [];

                    foreach ($attachments as $attachment) {
                        $this->assertAttachmentOwner($attachment, $ownerScope);
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
                            ->when(is_string($ownerScope), static fn ($query) => $query->where('owner_scope_key', $ownerScope))
                            ->delete();
                    }

                    if ($transferable !== []) {
                        $database->table($table)->whereIn('id', $transferable)
                            ->when(is_string($ownerScope), static fn ($query) => $query->where('owner_scope_key', $ownerScope))
                            ->update([
                                'term_id' => $destination->id,
                                'updated_at' => now(),
                            ]);
                    }
                });

            foreach ($context->children as $child) {
                $child->parent_id = $destination->id;
                $child->save();
                $this->domainEvents->dispatch(new TermChanged(
                    $child->id,
                    $child->taxonomy,
                    TermChangeOperation::Moved,
                    $child->revision,
                ), $child->getConnection());
            }

            $destination->revision++;
            $destination->save();
            $this->domainEvents->dispatch(new TermChanged(
                $destination->id,
                $destination->taxonomy,
                TermChangeOperation::Merged,
                $destination->revision,
            ), $destination->getConnection());
            $this->domainEvents->dispatch(new TermChanged(
                $source->id,
                $source->taxonomy,
                TermChangeOperation::Deleted,
                $source->revision,
            ), $source->getConnection());
            $source->delete();

            return $destination->refresh()->load('translations');
        }, TaxonomyConfiguration::transactionAttempts());
    }

    public function rebuild(Authenticatable|SystemActor $actor, ?string $taxonomy = null, bool $dryRun = false): int
    {
        $this->actors->authorize($actor, 'rebuild');
        $connection = TermModelRegistry::newInstance()->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($taxonomy, $dryRun): int {
            $changes = 0;

            foreach ($this->terms($taxonomy, lock: ! $dryRun)
                ->groupBy(static fn (Term $term): string => $term->taxonomy.'|'.$term->parent_key) as $children) {
                foreach ($children->values() as $index => $child) {
                    if ($child->position === $index) {
                        continue;
                    }

                    $changes++;

                    if ($dryRun) {
                        continue;
                    }

                    $child->position = $index;
                    $child->save();
                    $this->domainEvents->dispatch(new TermChanged(
                        $child->id,
                        $child->taxonomy,
                        TermChangeOperation::Reordered,
                        $child->revision,
                    ), $child->getConnection());
                }
            }

            return $changes;
        }, TaxonomyConfiguration::transactionAttempts());
    }

    public function validateMerge(
        Term|string $source,
        Term|string $destination,
        Authenticatable|SystemActor $actor,
        int $expectedSourceRevision,
        int $expectedDestinationRevision,
    ): void {
        $this->actors->authorize($actor, 'merge');
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

        DB::connection($connection)->transaction(
            fn () => $this->validator->validate(
                $sourceId,
                $destinationId,
                $expectedSourceRevision,
                $expectedDestinationRevision,
            ),
            TaxonomyConfiguration::transactionAttempts(),
        );
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

    private function assertAttachmentOwner(object $attachment, mixed $ownerScope): void
    {
        if (! is_string($ownerScope)) {
            return;
        }
        $attributes = get_object_vars($attachment);
        $type = $attributes['termable_type'] ?? null;
        $identifier = $attributes['termable_id'] ?? null;
        $modelClass = is_string($type) ? ($this->owners->all()[$type] ?? null) : null;
        if (! is_string($modelClass) || (! is_string($identifier) && ! is_int($identifier))) {
            throw TaxonomyOwnership::violation('A Taxonomy attachment has an unknown canonical owner.');
        }
        $owner = (new $modelClass)->newQueryWithoutScopes()->whereKey($identifier)->first();
        if (! $owner instanceof Model
            || $this->owners->resolve($owner, lock: true)->getRawOriginal('owner_scope_key') !== $ownerScope) {
            throw TaxonomyOwnership::violation('A Taxonomy attachment belongs to another owner.');
        }
    }

    /** @return Collection<int,Term> */
    private function terms(?string $taxonomy, bool $lock = false): Collection
    {
        if ($taxonomy !== null && $taxonomy !== '') {
            $definitions = [$this->taxonomies->get($taxonomy)];
        } else {
            $definitions = array_values($this->taxonomies->all());
        }

        /** @var Collection<int,Term> $terms */
        $terms = new Collection;

        foreach ($definitions as $definition) {
            $query = $definition->model::query()
                ->where('taxonomy', $definition->taxonomy);

            if ($lock) {
                $query->lockForUpdate();
            }

            $terms->push(...$query
                ->orderBy('parent_key')
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->all());
        }

        return $terms;
    }

    private function connectionFor(): ?string
    {
        return TermModelRegistry::newInstance()->getConnectionName();
    }
}
