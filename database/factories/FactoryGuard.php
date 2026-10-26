<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Database\Factories;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Taxonomy\Ownership\TaxonomyOwnership;

/**
 * Checks fixture identities against native persistence and native ownership admission.
 *
 * @internal
 */
final class FactoryGuard
{
    /** Require a scalar native identifier before lookup or morph serialization. */
    public static function identifier(mixed $key): int|string
    {
        if ((! is_string($key) && ! is_int($key)) || $key === '') {
            throw new InvalidArgumentException('Factory identities require a non-empty native scalar identifier.');
        }

        return $key;
    }

    /** Require a persisted parent on the fixture's effective connection. */
    public static function parent(Model $parent, Model $child): void
    {
        $key = $parent->getKey();
        if (! $parent->exists || (! is_string($key) && ! is_int($key))
            || $key === '' || $parent->getRawOriginal($parent->getKeyName()) !== $key) {
            throw new InvalidArgumentException('Factory parents require an unchanged persisted native identifier.');
        }
        if ($parent->getConnection() !== $child->getConnection()) {
            throw new InvalidArgumentException('Factory parents and children require the same effective connection.');
        }
        if (TaxonomyOwnership::declared()) {
            $container = Container::getInstance();
            $resource = $container->make(OwnershipResourceRegistry::class)->forModel($parent);
            $container->make(OwnershipBoundary::class)->assertRecord($parent, $resource->key);
        }
    }

    /** Validate a required native host morph identity before child persistence. */
    public static function owner(Model $child, string $typeColumn, string $idColumn): Model
    {
        $type = $child->getAttribute($typeColumn);
        $key = $child->getAttribute($idColumn);
        if (! is_string($type) || $type === '' || (! is_string($key) && ! is_int($key)) || $key === '') {
            throw new InvalidArgumentException('This factory requires forOwner() with a persisted real host model.');
        }
        $class = Relation::getMorphedModel($type) ?? $type;
        if (! is_a($class, Model::class, true) || $class === Model::class) {
            throw new InvalidArgumentException('Factory ownership requires a concrete native morph model.');
        }
        $prototype = new $class;
        if ($prototype->getConnection() !== $child->getConnection()) {
            throw new InvalidArgumentException('Factory owners require the fixture connection.');
        }
        $owner = $prototype->newQuery()->findOrFail($key);
        self::parent($owner, $child);
        if ($owner->getMorphClass() !== $type) {
            throw new InvalidArgumentException('Factory owners require their native morph identity.');
        }

        return $owner;
    }

    /** Apply admitted root ownership without activating or adopting Ownership. */
    public static function root(Model $model, string $resource): void
    {
        if (TaxonomyOwnership::declared()) {
            $model->forceFill(Container::getInstance()->make(OwnershipBoundary::class)->attributes($resource));
        }
    }

    /** Copy persisted parent ownership after native admission. */
    public static function inherit(Model $model, Model $parent, bool $withOwnershipKey = false): void
    {
        self::parent($parent, $model);

    }

    private function __construct() {}
}
