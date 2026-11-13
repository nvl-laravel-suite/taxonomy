<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use InvalidArgumentException;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Models\TermTranslation;
use Nvl\Taxonomy\Ownership\TaxonomyOwnership;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyDoctorCheck;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Throwable;

/**
 * Inspects taxonomy schemas and registries without mutation.
 *
 * @internal
 */
final readonly class TaxonomyDoctor
{
    /**
     * Create the schema, registry, and data diagnostic service.
     */
    public function __construct(
        private TaxonomyRegistry $taxonomies,
        private TaxonomyOwnerRegistry $owners,
        private OwnershipReadiness $readiness,
    ) {}

    /**
     * Return every non-mutating taxonomy readiness check.
     *
     * @return list<TaxonomyDoctorCheck>
     */
    public function inspect(): array
    {
        $connection = TermModelRegistry::newInstance()->getConnection();
        $schema = $connection->getSchemaBuilder();
        $owned = $this->ownershipDeclared();
        $ownerScopeColumns = $owned ? ['owner_scope_key'] : [];
        $tables = [
            TaxonomyConfiguration::table(TaxonomyTables::Terms) => [
                'id', 'taxonomy', 'parent_id', 'parent_key', 'slug', 'position', 'meta',
                'revision', 'created_at', 'updated_at', ...$ownerScopeColumns,
            ],
            TaxonomyConfiguration::table(TaxonomyTables::I18n) => [
                'id', 'term_id', 'locale', 'name', 'description', 'created_at', 'updated_at', ...$ownerScopeColumns,
            ],
            TaxonomyConfiguration::table(TaxonomyTables::Termables) => [
                'id', 'term_id', 'termable_type', 'termable_id', 'taxonomy', 'position',
                'created_at', 'updated_at', ...$ownerScopeColumns,
            ],
        ];
        $checks = [];
        $schemaCompatible = true;

        foreach ($tables as $table => $required) {
            try {
                [$exists, $missing] = $this->probe($connection, static function () use ($schema, $table, $required): array {
                    $exists = $schema->hasTable($table);
                    $missing = $exists
                        ? array_values(array_filter(
                            $required,
                            static fn (string $column): bool => ! $schema->hasColumn($table, $column),
                        ))
                        : $required;

                    return [$exists, $missing];
                });
            } catch (Throwable) {
                $exists = false;
                $missing = $required;
            }
            $checks[] = new TaxonomyDoctorCheck(
                key: "schema.{$table}",
                severity: 'error',
                passed: $exists && $missing === [],
                message: $exists && $missing === []
                    ? "Table [{$table}] is compatible."
                    : "Table [{$table}] is missing: ".implode(', ', $missing).'.',
            );
            $schemaCompatible = $schemaCompatible && $exists && $missing === [];
        }

        $checks[] = $this->indexCheck(
            $connection,
            $schema,
            TaxonomyConfiguration::table(TaxonomyTables::Terms),
            [
                ['taxonomy', 'id'],
                $owned ? ['owner_scope_key', 'taxonomy', 'parent_key', 'slug'] : ['taxonomy', 'parent_key', 'slug'],
            ],
        );
        $checks[] = $this->indexCheck(
            $connection,
            $schema,
            TaxonomyConfiguration::table(TaxonomyTables::I18n),
            [$owned ? ['owner_scope_key', 'term_id', 'locale'] : ['term_id', 'locale']],
        );
        $checks[] = $this->indexCheck(
            $connection,
            $schema,
            TaxonomyConfiguration::table(TaxonomyTables::Termables),
            [$owned ? ['owner_scope_key', 'term_id', 'termable_type', 'termable_id'] : ['term_id', 'termable_type', 'termable_id']],
        );
        $checks[] = $this->foreignKeyCheck(
            $connection,
            $schema,
            TaxonomyConfiguration::table(TaxonomyTables::Terms),
            [[
                'columns' => ['taxonomy', 'parent_id'],
                'foreign_table' => TaxonomyConfiguration::table(TaxonomyTables::Terms),
                'foreign_columns' => ['taxonomy', 'id'],
            ]],
        );
        $checks[] = $this->foreignKeyCheck(
            $connection,
            $schema,
            TaxonomyConfiguration::table(TaxonomyTables::I18n),
            [[
                'columns' => ['term_id'],
                'foreign_table' => TaxonomyConfiguration::table(TaxonomyTables::Terms),
                'foreign_columns' => ['id'],
            ]],
        );
        $checks[] = $this->foreignKeyCheck(
            $connection,
            $schema,
            TaxonomyConfiguration::table(TaxonomyTables::Termables),
            [[
                'columns' => ['taxonomy', 'term_id'],
                'foreign_table' => TaxonomyConfiguration::table(TaxonomyTables::Terms),
                'foreign_columns' => ['taxonomy', 'id'],
            ]],
        );

        $checks[] = new TaxonomyDoctorCheck(
            key: 'registry.taxonomies',
            severity: 'error',
            passed: $this->taxonomies->all() !== [],
            message: count($this->taxonomies->all()).' vocabularies are registered.',
        );
        if ($owned) {
            $ready = true;
            try {
                foreach (['taxonomy.terms', 'taxonomy.translations', 'taxonomy.attachments'] as $resource) {
                    $this->readiness->assertActive($resource);
                }
                foreach ($this->owners->all() as $ownerClass) {
                    TaxonomyOwnership::resource(new $ownerClass);
                }
            } catch (Throwable) {
                $ready = false;
            }
            $checks[] = new TaxonomyDoctorCheck(key: 'schema.ownership', severity: 'error', passed: $ready, message: $ready ? 'Canonical ownership storage is active.' : 'Canonical ownership storage is not ready.');
        }
        $checks[] = new TaxonomyDoctorCheck(
            key: 'registry.owners',
            severity: 'warning',
            passed: $this->owners->all() !== [],
            message: count($this->owners->all()).' owner aliases are registered.',
        );

        $unknownAllowedOwners = [];
        $connectionMismatches = [];
        $packageConnection = TermModelRegistry::newInstance()->getConnection()->getName();

        foreach ($this->taxonomies->all() as $definition) {
            foreach ($this->owners->unknownReferences($definition->allowedOwners) as $alias) {
                $unknownAllowedOwners[] = $definition->taxonomy.':'.$alias;
            }

            try {
                $compatible = (new ($definition->model))->getConnection()->getName() === $packageConnection;
            } catch (Throwable) {
                $compatible = false;
            }

            if (! $compatible) {
                $connectionMismatches[] = $definition->taxonomy;
            }
        }

        $checks[] = new TaxonomyDoctorCheck(
            key: 'registry.allowed_owners',
            severity: 'error',
            passed: $unknownAllowedOwners === [],
            message: $unknownAllowedOwners === []
                ? 'All allowed owner aliases are registered.'
                : 'Unknown allowed owner aliases: '.implode(', ', $unknownAllowedOwners).'.',
        );
        $checks[] = new TaxonomyDoctorCheck(
            key: 'registry.model_connections',
            severity: 'error',
            passed: $connectionMismatches === [],
            message: $connectionMismatches === []
                ? 'All taxonomy models use the package storage connection.'
                : 'Taxonomy models use incompatible connections: '
                    .implode(', ', $connectionMismatches).'.',
        );

        if ($schemaCompatible) {
            try {
                array_push($checks, ...$this->probe($connection, fn (): array => $this->dataChecks($connection)));
            } catch (Throwable $exception) {
                $checks[] = new TaxonomyDoctorCheck(
                    key: 'data.inspect',
                    severity: 'error',
                    passed: false,
                    message: 'Could not inspect taxonomy data: '.$exception->getMessage(),
                );
            }
        }

        return $checks;
    }

    /**
     * @param  list<list<string>>  $requiredUniqueColumns
     */
    private function indexCheck(
        ConnectionInterface $connection,
        SchemaBuilder $schema,
        string $table,
        array $requiredUniqueColumns,
    ): TaxonomyDoctorCheck {
        try {
            $tableExists = $this->probe($connection, static fn (): bool => $schema->hasTable($table));
        } catch (Throwable) {
            $tableExists = false;
        }

        if (! $tableExists) {
            return new TaxonomyDoctorCheck(
                key: "indexes.{$table}",
                severity: 'error',
                passed: false,
                message: "Table [{$table}] does not exist.",
            );
        }

        try {
            $indexes = $this->probe($connection, static fn (): array => $schema->getIndexes($table));
            $uniqueIndexes = collect($indexes)
                ->filter(static fn (array $index): bool => $index['unique'])
                ->map(static function (array $index): array {
                    $normalized = $index['columns'];
                    sort($normalized);

                    return $normalized;
                })
                ->all();
            $missing = array_values(array_filter(
                $requiredUniqueColumns,
                static function (array $columns) use ($uniqueIndexes): bool {
                    sort($columns);

                    return ! in_array($columns, $uniqueIndexes, true);
                },
            ));
        } catch (Throwable $exception) {
            return new TaxonomyDoctorCheck(
                key: "indexes.{$table}",
                severity: 'error',
                passed: false,
                message: "Could not inspect [{$table}] indexes: {$exception->getMessage()}",
            );
        }

        return new TaxonomyDoctorCheck(
            key: "indexes.{$table}",
            severity: 'error',
            passed: $missing === [],
            message: $missing === []
                ? "Table [{$table}] has the required unique indexes."
                : "Table [{$table}] lacks unique indexes for: "
                    .implode('; ', array_map(
                        static fn (array $columns): string => implode(', ', $columns),
                        $missing,
                    )).'.',
        );
    }

    /**
     * @param  list<array{columns: list<string>, foreign_table: string, foreign_columns: list<string>}>  $required
     */
    private function foreignKeyCheck(
        ConnectionInterface $connection,
        SchemaBuilder $schema,
        string $table,
        array $required,
    ): TaxonomyDoctorCheck {
        try {
            $tableExists = $this->probe($connection, static fn (): bool => $schema->hasTable($table));
        } catch (Throwable) {
            $tableExists = false;
        }

        if (! $tableExists) {
            return new TaxonomyDoctorCheck(
                key: "foreign_keys.{$table}",
                severity: 'error',
                passed: false,
                message: "Table [{$table}] does not exist.",
            );
        }

        try {
            $prefix = $schema->getConnection()->getTablePrefix();
            $rawForeignKeys = $this->probe($connection, static fn (): array => $schema->getForeignKeys($table));
            $foreignKeys = array_map(
                fn (array $foreignKey): array => $this->normalizeForeignKey($foreignKey, $prefix),
                $rawForeignKeys,
            );
            $required = array_map(
                fn (array $foreignKey): array => $this->normalizeForeignKey($foreignKey, $prefix),
                $required,
            );
            $missing = array_values(array_filter(
                $required,
                static fn (array $foreignKey): bool => ! in_array(
                    $foreignKey,
                    $foreignKeys,
                    true,
                ),
            ));
        } catch (Throwable $exception) {
            return new TaxonomyDoctorCheck(
                key: "foreign_keys.{$table}",
                severity: 'error',
                passed: false,
                message: "Could not inspect [{$table}] foreign keys: {$exception->getMessage()}",
            );
        }

        return new TaxonomyDoctorCheck(
            key: "foreign_keys.{$table}",
            severity: 'error',
            passed: $missing === [],
            message: $missing === []
                ? "Table [{$table}] has the required foreign keys."
                : "Table [{$table}] is missing ".count($missing).' required foreign key(s).',
        );
    }

    /**
     * Normalize a foreign key without losing local-to-foreign column pairing.
     *
     * @param  array<string, mixed>  $foreignKey
     * @return array{column_map: array<string, string>, foreign_table: string}
     */
    private function normalizeForeignKey(array $foreignKey, string $tablePrefix = ''): array
    {
        $columns = $foreignKey['columns'] ?? null;
        $foreignColumns = $foreignKey['foreign_columns'] ?? null;
        $foreignTable = $foreignKey['foreign_table'] ?? null;

        if (! is_array($columns)
            || ! is_array($foreignColumns)
            || ! is_string($foreignTable)
            || count($columns) !== count($foreignColumns)) {
            throw new InvalidArgumentException('A taxonomy foreign key definition is malformed.');
        }

        if ($tablePrefix !== '' && str_starts_with($foreignTable, $tablePrefix)) {
            $foreignTable = substr($foreignTable, strlen($tablePrefix));
        }

        $columnMap = [];

        foreach ($columns as $index => $column) {
            $foreignColumn = $foreignColumns[$index] ?? null;

            if (! is_string($column) || ! is_string($foreignColumn)) {
                throw new InvalidArgumentException(
                    'Taxonomy foreign key columns must be strings.',
                );
            }

            $columnMap[$column] = $foreignColumn;
        }

        ksort($columnMap);

        return [
            'column_map' => $columnMap,
            'foreign_table' => $foreignTable,
        ];
    }

    /**
     * @return list<TaxonomyDoctorCheck>
     */
    private function dataChecks(ConnectionInterface $connection): array
    {
        $terms = TermModelRegistry::newInstance()->getTable();
        $termables = (new Termable)->getTable();
        $translations = (new TermTranslation)->getTable();
        $parentKeyQuery = $connection->table($terms);
        $parentIdExpression = $connection->raw('CAST("parent_id" AS TEXT)');
        $invalidParentKeys = $parentKeyQuery
            ->where(static fn (QueryBuilder $query): QueryBuilder => $query
                ->whereNull('parent_id')
                ->where('parent_key', '!=', '__root__'))
            ->orWhere(static fn (QueryBuilder $query): QueryBuilder => $query
                ->whereNotNull('parent_id')
                ->where('parent_key', '!=', $parentIdExpression))
            ->count();
        $crossTaxonomyParents = $connection->table("{$terms} as child")
            ->join("{$terms} as parent", 'parent.id', '=', 'child.parent_id')
            ->whereColumn('child.taxonomy', '!=', 'parent.taxonomy')
            ->count();
        $attachmentMismatches = $connection->table("{$termables} as attachment")
            ->join("{$terms} as term", 'term.id', '=', 'attachment.term_id')
            ->whereColumn('attachment.taxonomy', '!=', 'term.taxonomy')
            ->count();
        $ownerScopeMismatches = 0;
        if ($this->ownershipDeclared()) {
            $scope = 'owner_scope_key';
            $ownerScopeMismatches += $connection->table("{$terms} as child")
                ->join("{$terms} as parent", 'parent.id', '=', 'child.parent_id')
                ->whereColumn("child.{$scope}", '!=', "parent.{$scope}")->count();
            $ownerScopeMismatches += $connection->table("{$termables} as attachment")
                ->join("{$terms} as term", 'term.id', '=', 'attachment.term_id')
                ->whereColumn("attachment.{$scope}", '!=', "term.{$scope}")->count();
            $ownerScopeMismatches += $connection->table("{$translations} as translation")
                ->join("{$terms} as term", 'term.id', '=', 'translation.term_id')
                ->whereColumn("translation.{$scope}", '!=', "term.{$scope}")->count();
        }
        $orphanTranslations = $connection->table("{$translations} as translation")
            ->leftJoin("{$terms} as term", 'term.id', '=', 'translation.term_id')
            ->whereNull('term.id')
            ->count();
        $hierarchyErrors = $this->hierarchyErrorCount();
        $registeredTaxonomies = array_keys($this->taxonomies->all());
        $registeredOwners = array_keys($this->owners->all());
        $unknownTaxonomyRows = $connection->table($terms)
            ->whereNotIn('taxonomy', $registeredTaxonomies)
            ->count()
            + $connection->table($termables)
                ->whereNotIn('taxonomy', $registeredTaxonomies)
                ->count();
        $unknownOwnerRows = $registeredOwners === []
            ? $connection->table($termables)->count()
            : $connection->table($termables)
                ->whereNotIn('termable_type', $registeredOwners)
                ->count();
        $exclusiveViolations = 0;

        foreach ($this->taxonomies->all() as $definition) {
            if (! $definition->exclusive) {
                continue;
            }

            $violations = $connection->table($termables)
                ->where('taxonomy', $definition->taxonomy)
                ->select(['termable_type', 'termable_id'])
                ->groupBy('termable_type', 'termable_id')
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->all();
            $exclusiveViolations += count($violations);
        }

        return [
            $this->countCheck(
                'data.parent_keys',
                $invalidParentKeys,
                'parent key mismatches',
            ),
            $this->countCheck(
                'data.parent_taxonomies',
                $crossTaxonomyParents,
                'cross-taxonomy parents',
            ),
            $this->countCheck(
                'data.attachment_taxonomies',
                $attachmentMismatches,
                'attachment taxonomy mismatches',
            ),
            $this->countCheck(
                'data.owner_scope',
                $ownerScopeMismatches,
                'cross-owner taxonomy relationships',
            ),
            $this->countCheck(
                'data.orphan_translations',
                $orphanTranslations,
                'orphan translation rows',
            ),
            $this->countCheck(
                'data.hierarchies',
                $hierarchyErrors,
                'cycle, flat-vocabulary, or maximum-depth violations',
            ),
            $this->countCheck(
                'data.registered_taxonomies',
                $unknownTaxonomyRows,
                'rows for unregistered taxonomies',
            ),
            $this->countCheck(
                'data.registered_owners',
                $unknownOwnerRows,
                'rows for unregistered owner aliases',
            ),
            $this->countCheck(
                'data.exclusive_attachments',
                $exclusiveViolations,
                'exclusive-vocabulary attachment violations',
            ),
        ];
    }

    private function hierarchyErrorCount(): int
    {
        $errors = 0;

        foreach ($this->taxonomies->all() as $definition) {
            $model = new ($definition->model);
            $parents = $model->getConnection()
                ->table($model->getTable())
                ->where('taxonomy', $definition->taxonomy)
                ->pluck('parent_id', 'id')
                ->all();

            foreach ($parents as $termId => $parentId) {
                if (! is_string($termId)
                    || ($parentId !== null && ! is_string($parentId))) {
                    $errors++;

                    continue;
                }

                if (! $definition->hierarchical && $parentId !== null) {
                    $errors++;

                    continue;
                }

                $visited = [$termId => true];
                $depth = 1;

                while ($parentId !== null) {
                    if (isset($visited[$parentId]) || ! array_key_exists($parentId, $parents)) {
                        $errors++;

                        break;
                    }

                    $visited[$parentId] = true;
                    $depth++;
                    $next = $parents[$parentId];
                    $parentId = is_string($next) ? $next : null;
                }

                if ($definition->maxDepth > 0 && $depth > $definition->maxDepth) {
                    $errors++;
                }
            }
        }

        return $errors;
    }

    private function countCheck(
        string $key,
        int $count,
        string $description,
    ): TaxonomyDoctorCheck {
        return new TaxonomyDoctorCheck(
            key: $key,
            severity: 'error',
            passed: $count === 0,
            message: "{$count} {$description} found.",
        );
    }

    private function ownershipDeclared(): bool
    {
        try {
            return TaxonomyOwnership::declared();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Isolate read-only probes in a savepoint when an outer transaction is active.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function probe(ConnectionInterface $connection, Closure $callback): mixed
    {
        return $connection->transactionLevel() > 0
            ? $connection->transaction($callback)
            : $callback();
    }
}
