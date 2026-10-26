<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Nvl\Support\Enums\SystemActor;

/**
 * Admits explicit background work or a fresh persisted actor granted taxonomy access.
 *
 * @internal
 */
final readonly class TaxonomyActorPolicy
{
    public function authorize(Authenticatable|SystemActor $actor, string $ability): void
    {
        if ($actor === SystemActor::Background) {
            Gate::forUser($actor)->authorize('taxonomy.'.$ability);

            return;
        }

        if (! $actor instanceof Model || ! $actor->exists || $actor->getKey() === null) {
            throw new AuthorizationException('A persisted taxonomy actor is required.');
        }

        $prototype = new ($actor::class);
        if ($actor->getTable() !== $prototype->getTable()
            || $actor->getKeyName() !== $prototype->getKeyName()
            || $actor->getConnection() !== $prototype->getConnection()
            || $actor->isDirty($actor->getKeyName())) {
            throw new AuthorizationException('The taxonomy actor identity has changed.');
        }

        $current = $prototype->newQuery()->whereKey($actor->getRawOriginal($actor->getKeyName()))->first();
        if (! $current instanceof Authenticatable) {
            throw new AuthorizationException('The taxonomy actor is unavailable.');
        }

        Gate::forUser($current)->authorize('taxonomy.'.$ability);
    }
}
