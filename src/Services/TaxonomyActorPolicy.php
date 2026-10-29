<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Nvl\Support\Auth\CanonicalActorResolver;
use Nvl\Support\Enums\SystemActor;

/**
 * Admits explicit background work or a fresh persisted actor granted taxonomy access.
 *
 * @internal
 */
final readonly class TaxonomyActorPolicy
{
    public function __construct(private CanonicalActorResolver $actors) {}

    public function authorize(Authenticatable|SystemActor $actor, string $ability, ?Model $owner = null, ?string $taxonomy = null, ?Model $related = null): Authenticatable|SystemActor
    {
        if ($actor === SystemActor::Background) {
            $current = $actor;
        } else {
            if (! $actor instanceof Model) {
                throw new AuthorizationException('A persisted taxonomy actor is required.');
            }
            $connection = $actor->getConnectionName();
            if ($connection !== null && $connection !== (new ($actor::class))->getConnection()->getName()) {
                throw new AuthorizationException('The taxonomy actor identity has changed.');
            }
            $current = $this->actors->resolve($actor);
        }

        $arguments = $owner === null ? ($taxonomy === null ? [] : [$taxonomy]) : [$owner, $taxonomy, $related];
        Gate::forUser($current)->authorize('taxonomy.'.$ability, $arguments);

        return $current;
    }
}
