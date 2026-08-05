<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nvl\Taxonomy\Data\TaxonomyDoctorCheckData;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Models\TermTranslation;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Throwable;

/**
 * Inspects taxonomy schemas and registries without mutation.
 */
final readonly class TaxonomyDoctor
{
    /**
     * Create the schema, registry, and data diagnostic service.
     */
    public function __construct(
        private TaxonomyRegistry $taxonomies,
        private TaxonomyOwnerRegistry $owners,
    ) {}

    /**
     * Return every non-mutating taxonomy readiness check.
     *
     * @return list<TaxonomyDoctorCheckData>
     */
    public function inspect(): array
    {
        $schema = Schema::connection(TaxonomyConfiguration::connection());
        $tables = [
            TaxonomyConfiguration::table('terms', 'terms') => [
                'id', 'taxonomy', 'parent_id', 'parent_key', 'slug', 'position', 'meta',
                'revision', 'created_at', 'updated_at',
            ],
            TaxonomyConfiguration::table('terms_i18n', 'terms_i18n') => [
                'id', 'term_id', 'locale', 'name', 'description', 'created_at', 'updated_at',
            ],
            TaxonomyConfiguration::table('termables', 'termables') => [
                'id', 'term_id', 'termable_type', 'termable_id', 'taxonomy', 'position',
                'created_at', 'updated_at',
            ],
        ];
        $checks = [];
        $schemaCompatible = true;

        foreach ($tables as $table => $required) {
            $exists = $schema->hasTable($table);
            $missing = $exists
                ? array_values(array_filter(
                    $required,
                    static fn (string $column): bool => ! $schema->hasColumn($table, $column),
                ))
                : $required;
            $checks[] = new TaxonomyDoctorCheckData(
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
            TaxonomyConfiguration::table('terms', 'terms'),
            [
                ['taxonomy', 'id'],
                ['taxonomy', 'parent_key', 'slug'],
            ],
        );
        $checks[] = $this->indexCheck(
            TaxonomyConfiguration::table('terms_i18n', 'terms_i18n'),
            [['term_id', 'locale']],
        );
        $checks[] = $this->indexCheck(
            TaxonomyConfiguration::table('termables', 'termables'),
            [['term_id', 'termable_type', 'termable_id']],
        );
        $checks[] = $this->foreignKeyCheck(
            TaxonomyConfiguration::table('terms', 'terms'),
            [[
                'columns' => ['taxonomy', 'parent_id'],
                'foreign_table' => TaxonomyConfiguration::table('terms', 'terms'),
                'foreign_columns' => ['taxonomy', 'id'],
            ]],
        );
        $checks[] = $this->foreignKeyCheck(
            TaxonomyConfiguration::table('terms_i18n', 'terms_i18n'),
            [[
                'columns' => ['term_id'],
                'foreign_table' => TaxonomyConfiguration::table('terms', 'terms'),
                'foreign_columns' => ['id'],
            ]],
        );
        $checks[] = $this->foreignKeyCheck(
            TaxonomyConfiguration::table('termables', 'termables'),
            [[
                'columns' => ['taxonomy', 'term_id'],
                'foreign_table' => TaxonomyConfiguration::table('terms', 'terms'),
                'foreign_columns' => ['taxonomy', 'id'],
            ]],
        );

        $checks[] = new TaxonomyDoctorCheckData(
            key: 'registry.taxonomies',
            severity: 'error',
            passed: $this->taxonomies->all() !== [],
            message: count($this->taxonomies->all()).' vocabularies are registered.',
        );
        $checks[] = new TaxonomyDoctorCheckData(
            key: 'registry.owners',
            severity: 'warning',
            passed: $this->owners->all() !== [],
            message: count($this->owners->all()).' owner aliases are registered.',
        );

        $unknownAllowedOwners = [];
        $connectionMismatches = [];

        foreach ($this->taxonomies->all() as $definition) {
            foreach (array_diff($definition->allowedOwners, array_keys($this->owners->all())) as $alias) {
                $unknownAllowedOwners[] = $definition->taxonomy.':'.$alias;
            }

            $modelConnection = (new ($definition->model))->getConnectionName();

            if ($modelConnection !== (new Term)->getConnectionName()) {
                $connectionMismatches[] = $definition->taxonomy;
            }
        }

        $checks[] = new TaxonomyDoctorCheckData(
            key: 'registry.allowed_owners',
            severity: 'error',
            passed: $unknownAllowedOwners === [],
            message: $unknownAllowedOwners === []
                ? 'All allowed owner aliases are registered.'
                : 'Unknown allowed owner aliases: '.implode(', ', $unknownAllowedOwners).'.',
        );
        $checks[] = new TaxonomyDoctorCheckData(
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
                array_push($checks, ...$this->dataChecks());
            } catch (Throwable $exception) {
                $checks[] = new TaxonomyDoctorCheckData(
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
    private function indexCheck(string $table, array $requiredUniqueColumns): TaxonomyDoctorCheckData
    {
        $schema = Schema::connection(TaxonomyConfiguration::connection());

        if (! $schema->hasTable($table)) {
            return new TaxonomyDoctorCheckData(
                key: "indexes.{$table}",
                severity: 'error',
                passed: false,
                message: "Table [{$table}] does not exist.",
            );
        }

        try {
            $uniqueIndexes = collect($schema->getIndexes($table))
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
            return new TaxonomyDoctorCheckData(
                key: "indexes.{$table}",
                severity: 'error',
                passed: false,
                message: "Could not inspect [{$table}] indexes: {$exception->getMessage()}",
            );
        }

        return new TaxonomyDoctorCheckData(
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
    private function foreignKeyCheck(string $table, array $required): TaxonomyDoctorCheckData
    {
        $schema = Schema::connection(TaxonomyConfiguration::connection());

        if (! $schema->hasTable($table)) {
            return new TaxonomyDoctorCheckData(
                key: "foreign_keys.{$table}",
                severity: 'error',
                passed: false,
                message: "Table [{$table}] does not exist.",
            );
        }

        try {
            $foreignKeys = array_map(
                $this->normalizeForeignKey(...),
                $schema->getForeignKeys($table),
            );
            $required = array_map($this->normalizeForeignKey(...), $required);
            $missing = array_values(array_filter(
                $required,
                static fn (array $foreignKey): bool => ! in_array(
                    $foreignKey,
                    $foreignKeys,
                    true,
                ),
            ));
        } catch (Throwable $exception) {
            return new TaxonomyDoctorCheckData(
                key: "foreign_keys.{$table}",
                severity: 'error',
                passed: false,
                message: "Could not inspect [{$table}] foreign keys: {$exception->getMessage()}",
            );
        }

        return new TaxonomyDoctorCheckData(
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
    private function normalizeForeignKey(array $foreignKey): array
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
     * @return list<TaxonomyDoctorCheckData>
     */
    private function dataChecks(): array
    {
        $connection = DB::connection((new Term)->getConnectionName());
        $terms = (new Term)->getTable();
        $termables = (new Termable)->getTable();
        $translations = (new TermTranslation)->getTable();
        $parentKeyQuery = $connection->table($terms);
        $parentIdExpression = match ($connection->getDriverName()) {
            'pgsql', 'sqlite' => $connection->raw('CAST("parent_id" AS TEXT)'),
            'sqlsrv' => $connection->raw('CAST([parent_id] AS NVARCHAR(36))'),
            'mariadb', 'mysql' => $connection->raw('CAST(`parent_id` AS CHAR)'),
            default => $connection->raw('CAST(parent_id AS CHAR)'),
        };
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
            $parents = $definition->model::query()
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
    ): TaxonomyDoctorCheckData {
        return new TaxonomyDoctorCheckData(
            key: $key,
            severity: 'error',
            passed: $count === 0,
            message: "{$count} {$description} found.",
        );
    }
}
