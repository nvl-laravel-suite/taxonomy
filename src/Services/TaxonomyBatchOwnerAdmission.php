<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Owners\OwnerIdentity;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Relations\ExactTaxonomyText;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/** Reloads captured native owner identities through retained canonical scopes. */
final readonly class TaxonomyBatchOwnerAdmission
{
    /** Retain the registered capability and ownership runtimes. */
    public function __construct(
        private Repository $configuration,
        private TaxonomyOwnerRegistry $owners,
        private TaxonomyRegistry $vocabularies,
        private TenantResourceRegistry $resources,
        private TenantBoundary $boundary,
    ) {}

    /**
     * Validate complete input then reload every concrete owner class once.
     *
     * @param  list<string>  $vocabularies
     */
    public function admit(OwnerBatch $input, array $vocabularies): OwnerBatch
    {
        $groups = [];
        foreach ($input->identities() as $index => $identity) {
            $owner = $input->owners()[$index];
            $this->assertPrototype($owner);
            foreach ($vocabularies as $vocabulary) {
                $this->assertVocabulary($owner, $vocabulary);
            }
            $groups[$identity->model][] = $identity->id;
        }
        $loaded = [];
        foreach ($groups as $class => $ids) {
            $prototype = new $class;
            $query = $this->scope($prototype->newQuery()->withoutEagerLoads());
            $query->where(function (Builder $keys) use ($prototype, $ids): void {
                foreach ($ids as $id) {
                    $keys->orWhere(fn (Builder $key) => self::exact($key, $prototype->getQualifiedKeyName(), $id));
                }
            });
            foreach ($query->get() as $owner) {
                $identity = OwnerIdentity::fromModel($owner);
                $loaded[$identity->type][$identity->id] = $owner;
            }
        }
        $canonical = [];
        foreach ($input->identities() as $identity) {
            $canonical[] = $loaded[$identity->type][$identity->id] ?? $this->unavailable();
        }

        return OwnerBatch::fromModels($canonical);
    }

    /** Reject native class, declared connection and table drift before SQL. */
    public function assertPrototype(Model $owner): void
    {
        $this->owners->aliasFor($owner);
        $prototype = new ($owner::class);
        $connection = (new Term)->getConnection();
        if (($prototype->getConnectionName() !== null && $owner->getConnectionName() !== $prototype->getConnectionName())
            || ($owner->getConnectionName() !== null && $owner->getConnectionName() !== $prototype->getConnection()->getName())
            || $owner->getTable() !== $prototype->getTable()
            || $prototype->getConnection() !== $connection
            || $owner->getConnection() !== $connection) {
            $this->unavailable();
        }
        if ($this->configuration->get('nvl-tenancy.enabled') === true) {
            $this->resources->forModel($prototype);
        }
    }

    /** Require declared owner capabilities for this exact registered vocabulary. */
    public function assertVocabulary(Model $owner, string $vocabulary): void
    {
        $this->owners->aliasFor($owner);
        $definition = $this->vocabularies->get($vocabulary);
        if ($definition->allowedOwners !== []
            && ! in_array($owner::class, array_map($this->owners->model(...), $definition->allowedOwners), true)) {
            throw new InvalidArgumentException('The owner is not allowed for this registered taxonomy vocabulary.');
        }
    }

    /**
     * Restore native live ownership around grouped caller predicates.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function scope(Builder $query): Builder
    {
        $this->assertHostQuery($query);
        $this->groupPredicates($query);
        $owner = new ($query->getModel()::class);
        if (in_array(SoftDeletes::class, class_uses_recursive($owner), true) && method_exists($owner, 'getQualifiedDeletedAtColumn')) {
            $column = $owner->getQualifiedDeletedAtColumn();
            if (! is_string($column)) {
                $this->unavailable();
            }
            $query->whereNull($column);
        }
        if ($this->configuration->get('nvl-tenancy.enabled') === true) {
            $this->boundary->query($query, $this->resources->forModel($owner)->key);
        }

        return $query;
    }

    /**
     * Reject altered actual builder storage and compound host queries.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     */
    public function assertHostQuery(Builder $query): void
    {
        $owner = $query->getModel();
        $this->assertPrototype($owner);
        if ($query->getConnection() !== $owner->getConnection()
            || $query->getQuery()->from !== $owner->getTable()
            || $query->getQuery()->unions !== null) {
            $this->unavailable();
        }
    }

    /**
     * Retain only WHERE state and WHERE bindings inside a mandatory AND group.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     */
    private function groupPredicates(Builder $query): void
    {
        $base = $query->getQuery();
        if ($base->wheres === []) {
            return;
        }
        $nested = $base->forNestedWhere();
        $nested->wheres = $base->wheres;
        $nested->setBindings($base->getRawBindings()['where'], 'where');
        $base->wheres = [];
        $base->setBindings([], 'where');
        $base->addNestedWhereQuery($nested);
    }

    /**
     * Compare canonical textual keys or morph types using exact byte predicates.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     */
    public static function exact(Builder $query, string $column, string $value): void
    {
        $connection = $query->getModel()->getConnection();
        $text = ExactTaxonomyText::text($connection->getQueryGrammar()->wrap($column), $connection->getDriverName());
        $query->whereRaw(ExactTaxonomyText::value($text, $connection->getDriverName()), [$value, $value]);
    }

    /** Fail with the existing enabled or disabled canonical owner denial semantics. */
    private function unavailable(): never
    {
        if ($this->configuration->get('nvl-tenancy.enabled') === true) {
            throw new TenantBoundaryViolation('The canonical taxonomy owner is unavailable in the active storage scope.');
        }

        throw new InvalidArgumentException('The canonical taxonomy owner is unavailable.');
    }
}
