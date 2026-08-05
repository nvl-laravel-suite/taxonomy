<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

/**
 * Maps stable owner aliases to consumer model classes.
 */
final class TaxonomyOwnerRegistry
{
    /** @var array<string, class-string<Model>> */
    private array $owners = [];

    /**
     * Register a stable polymorphic alias for one taxonomy owner model.
     */
    public function register(string $alias, string $model): void
    {
        $alias = trim($alias);

        if (preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $alias) !== 1
            || ! is_a($model, Model::class, true)) {
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

        $morphedModel = Relation::getMorphedModel($alias);

        if ($morphedModel !== null && $morphedModel !== $model) {
            throw new InvalidArgumentException(
                "Morph alias [{$alias}] is already registered for [{$morphedModel}].",
            );
        }

        foreach (Relation::morphMap() as $morphAlias => $morphModel) {
            if ($morphModel === $model && $morphAlias !== $alias) {
                throw new InvalidArgumentException(
                    "Model [{$model}] already uses morph alias [{$morphAlias}].",
                );
            }
        }

        $this->owners[$alias] = $model;
        ksort($this->owners);

        Relation::morphMap([$alias => $model], merge: true);
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
