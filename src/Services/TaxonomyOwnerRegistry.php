<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Contracts\TenantParentResolver;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Taxonomy\Models\Term;

/**
 * Maps stable owner aliases to consumer model classes.
 */
final class TaxonomyOwnerRegistry implements TenantParentResolver
{
    /** @var array<string, class-string<Model>> */
    private array $owners = [];

    /** Create the canonical owner registry. */
    public function __construct(
        private readonly TenantResourceRegistry $resources,
        private readonly TenantBoundary $boundary,
        private readonly OwnerRegistry $identitiesRegistry,
    ) {}

    /**
     * Register a stable polymorphic alias for one taxonomy owner model.
     */
    public function register(string $alias, string $model): void
    {
        $alias = trim($alias);
        $reference = $model;
        $model = is_a($reference, Model::class, true) ? $reference : $this->identitiesRegistry->model($reference);

        if ($reference !== $model && $reference !== $alias) {
            throw new InvalidArgumentException("Taxonomy owner [{$alias}] must use its canonical shared alias [{$reference}].");
        }

        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $alias) !== 1) {
            throw new InvalidArgumentException(
                "Taxonomy owner alias [{$alias}] or model [{$model}] is invalid.",
            );
        }

        $registeredModel = $this->owners[$alias] ?? null;

        if ($registeredModel !== null && $registeredModel !== $model) {
            throw new InvalidArgumentException(
                "Taxonomy owner alias [{$alias}] is already registered for [{$registeredModel}].",
            );
        }

        $registeredAlias = array_search($model, $this->owners, true);

        if (is_string($registeredAlias) && $registeredAlias !== $alias) {
            throw new InvalidArgumentException(
                "Taxonomy owner model [{$model}] is already registered as [{$registeredAlias}].",
            );
        }

        $this->identitiesRegistry->reference($reference, "taxonomy.owners.{$alias}", $alias, true);
        $this->owners[$alias] = $model;
        ksort($this->owners);

    }

    /**
     * Return the exact stable alias for one concrete owner model.
     */
    public function aliasFor(Model $owner): string
    {
        $alias = array_search($owner::class, $this->owners, true);

        if (is_string($alias)) {
            return $alias;
        }

        throw new InvalidArgumentException(
            'Model ['.$owner::class.'] is not registered as a taxonomy owner.',
        );
    }

    /** Reload one registered owner through its declared tenant resource. */
    public function resolve(Model $owner, bool $lock = false): Model
    {
        $this->aliasFor($owner);
        $modelClass = $owner::class;
        $canonical = new $modelClass;

        $identifier = $owner->getRawOriginal($owner->getKeyName());

        if (! $owner->exists || (! is_string($identifier) && ! is_int($identifier))) {
            throw new TenantBoundaryViolation('Taxonomy owners must be persisted canonical records.');
        }

        $query = $canonical->newQuery()->whereKey($identifier);
        $resource = null;

        if (config('tenancy.enabled') === true) {
            if ($canonical->getConnection() !== (new Term)->getConnection()) {
                throw new TenantConfigurationInvalid('Taxonomy owners must share the Taxonomy connection.');
            }

            $resource = $this->resources->forModel($owner);
            $this->boundary->query($query, $resource->key);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        $resolved = $query->first();

        if (! $resolved instanceof Model) {
            if (config('tenancy.enabled') === true) {
                throw new TenantBoundaryViolation('The canonical taxonomy owner is unavailable.');
            }

            throw new InvalidArgumentException('The canonical taxonomy owner is unavailable.');
        }

        if ($resource !== null) {
            $this->boundary->assertRecord($resolved, $resource->key);
        }

        return $resolved;
    }

    /**
     * Return explicitly registered morph identities for inherited ownership.
     *
     * @return array<string, class-string<Model>>
     */
    public function types(): array
    {
        return $this->owners;
    }

    /**
     * Return every registered owner model by stable alias.
     *
     * @return array<string, class-string<Model>>
     */
    public function all(): array
    {
        return $this->owners;
    }
}
