<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Support;

use InvalidArgumentException;
use Nvl\Taxonomy\Exceptions\UnknownTaxonomyException;
use Nvl\Taxonomy\Models\Term;

/**
 * Validates and resolves immutable vocabulary definitions by canonical alias.
 */
final class TaxonomyRegistry
{
    /** @var array<string, TaxonomyDefinition> */
    protected array $definitions = [];

    /**
     * Create the registry and load configured vocabulary definitions.
     */
    public function __construct()
    {
        $this->loadFromConfig();
    }

    protected function loadFromConfig(): void
    {
        $taxonomies = config('taxonomy.taxonomies', []);

        if (! is_array($taxonomies)) {
            throw new InvalidArgumentException('Taxonomy definitions must be an array.');
        }

        foreach ($taxonomies as $taxonomy => $config) {
            if (! is_string($taxonomy) || ! is_array($config)) {
                throw new InvalidArgumentException('Taxonomy definitions must use string aliases and arrays.');
            }

            if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $taxonomy) !== 1) {
                throw new InvalidArgumentException(
                    "Taxonomy alias [{$taxonomy}] must be a canonical lowercase identifier.",
                );
            }

            $model = $config['model'] ?? Term::class;
            $sort = $config['sort'] ?? 'position';

            if (! is_string($model) || ! is_a($model, Term::class, true)) {
                throw new InvalidArgumentException("Taxonomy [{$taxonomy}] model must extend Term.");
            }

            if (! is_string($sort) || ! in_array($sort, ['position', 'slug', 'created_at'], true)) {
                throw new InvalidArgumentException("Taxonomy [{$taxonomy}] has an invalid sort.");
            }

            $hierarchical = $config['hierarchical'] ?? false;
            $exclusive = $config['exclusive'] ?? false;
            $open = $config['open'] ?? false;
            $maxDepth = $config['max_depth'] ?? 0;

            if (! is_bool($hierarchical)
                || ! is_bool($exclusive)
                || ! is_bool($open)
                || ! is_int($maxDepth)
                || $maxDepth < 0) {
                throw new InvalidArgumentException("Taxonomy [{$taxonomy}] has invalid structural settings.");
            }

            $metadataRules = [];

            foreach (is_array($config['metadata_rules'] ?? null) ? $config['metadata_rules'] : [] as $field => $rules) {
                if (! is_string($field) || ! is_array($rules)) {
                    throw new InvalidArgumentException("Taxonomy [{$taxonomy}] has invalid metadata rules.");
                }

                $metadataRules[$field] = array_values($rules);
            }

            $allowedOwners = $config['allowed_owners'] ?? [];

            if (! is_array($allowedOwners)
                || array_filter($allowedOwners, 'is_string') !== $allowedOwners) {
                throw new InvalidArgumentException(
                    "Taxonomy [{$taxonomy}] has an invalid owner allowlist.",
                );
            }

            $this->register(new TaxonomyDefinition(
                taxonomy: $taxonomy,
                model: $model,
                hierarchical: $hierarchical,
                exclusive: $exclusive,
                open: $open,
                maxDepth: $maxDepth,
                sort: $sort,
                allowedOwners: array_values(array_unique($allowedOwners)),
                metadataRules: $metadataRules,
            ));
        }
    }

    /**
     * Register one validated immutable vocabulary definition.
     */
    public function register(TaxonomyDefinition $definition): void
    {
        $this->assertValid($definition);

        if (isset($this->definitions[$definition->taxonomy])) {
            throw new InvalidArgumentException(
                "Taxonomy [{$definition->taxonomy}] is already registered.",
            );
        }

        $this->definitions[$definition->taxonomy] = $definition;
    }

    /**
     * Return one registered vocabulary definition.
     */
    public function get(string $taxonomy): TaxonomyDefinition
    {
        return $this->definitions[$taxonomy]
            ?? throw new UnknownTaxonomyException("Taxonomy [$taxonomy] is not defined in config.");
    }

    /**
     * Return every registered vocabulary by canonical alias.
     *
     * @return array<string, TaxonomyDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    private function assertValid(TaxonomyDefinition $definition): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $definition->taxonomy) !== 1) {
            throw new InvalidArgumentException(
                "Taxonomy alias [{$definition->taxonomy}] must be a canonical lowercase identifier.",
            );
        }

        if (! $this->isTermModel($definition->model)) {
            throw new InvalidArgumentException(
                "Taxonomy [{$definition->taxonomy}] model must extend Term.",
            );
        }

        if (! in_array($definition->sort, ['position', 'slug', 'created_at'], true)
            || $definition->maxDepth < 0) {
            throw new InvalidArgumentException(
                "Taxonomy [{$definition->taxonomy}] has invalid structural settings.",
            );
        }

        if (! $this->hasValidAllowedOwners($definition->allowedOwners)) {
            throw new InvalidArgumentException(
                "Taxonomy [{$definition->taxonomy}] has an invalid owner allowlist.",
            );
        }

        if (! $this->hasValidMetadataRules($definition->metadataRules)) {
            throw new InvalidArgumentException(
                "Taxonomy [{$definition->taxonomy}] has invalid metadata rules.",
            );
        }
    }

    private function isTermModel(string $model): bool
    {
        return is_a($model, Term::class, true);
    }

    /**
     * @param  array<mixed>  $owners
     */
    private function hasValidAllowedOwners(array $owners): bool
    {
        return array_is_list($owners)
            && array_filter($owners, 'is_string') === $owners
            && count(array_unique($owners)) === count($owners);
    }

    /**
     * @param  array<mixed>  $metadataRules
     */
    private function hasValidMetadataRules(array $metadataRules): bool
    {
        foreach ($metadataRules as $field => $rules) {
            if (! is_string($field) || $field === '' || ! is_array($rules)) {
                return false;
            }
        }

        return true;
    }
}
