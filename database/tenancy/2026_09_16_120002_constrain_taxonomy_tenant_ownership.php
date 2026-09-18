<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

return new class extends Migration
{
    /** Apply verified tenant-local tree, translation, and attachment constraints. */
    public function up(): void
    {
        $schema = Schema::connection(TaxonomyConfiguration::connection());
        $terms = TaxonomyConfiguration::table(TaxonomyTables::Terms, TaxonomyTables::Terms);
        $translations = TaxonomyConfiguration::table(TaxonomyTables::I18n, TaxonomyTables::I18n);
        $attachments = TaxonomyConfiguration::table(TaxonomyTables::Termables, TaxonomyTables::Termables);

        $this->dropForeignColumns($schema, $terms, ['taxonomy', 'parent_id']);
        $this->dropForeignColumns($schema, $translations, ['term_id']);
        $this->dropForeignColumns($schema, $attachments, ['taxonomy', 'term_id']);
        $this->dropIndex($schema, $terms, 'terms_sibling_slug_unique', true);
        $this->dropIndex($schema, $translations, 'terms_i18n_owner_locale_unique', true);
        $this->dropUniqueColumns($schema, $attachments, ['term_id', 'termable_id', 'termable_type']);

        foreach ([$terms, $translations, $attachments] as $table) {
            $this->required($schema, $table, 'tenant_id', false);
        }

        $this->unique($schema, $terms, ['tenant_id', 'taxonomy', 'parent_key', 'slug'], 'terms_tenant_sibling_slug_unique');
        $this->unique($schema, $terms, ['tenant_id', 'taxonomy', 'id'], 'terms_tenant_taxonomy_id_unique');
        $this->unique($schema, $terms, ['tenant_id', 'id'], 'terms_tenant_id_unique');
        $this->index($schema, $terms, ['tenant_id', 'taxonomy', 'parent_id', 'position'], 'terms_tenant_tree_index');
        $this->foreign($schema, $terms, ['tenant_id', 'taxonomy', 'parent_id'], $terms, ['tenant_id', 'taxonomy', 'id'], 'terms_tenant_parent_foreign', false);

        $this->unique($schema, $translations, ['tenant_id', 'term_id', 'locale'], 'terms_i18n_tenant_locale_unique');
        $this->foreign($schema, $translations, ['tenant_id', 'term_id'], $terms, ['tenant_id', 'id'], 'terms_i18n_tenant_term_foreign');

        $this->unique($schema, $attachments, ['tenant_id', 'term_id', 'termable_type', 'termable_id'], 'termables_tenant_owner_unique');
        $this->index($schema, $attachments, ['tenant_id', 'termable_type', 'termable_id', 'taxonomy', 'position'], 'termables_tenant_owner_tax_pos_index');
        $this->foreign($schema, $attachments, ['tenant_id', 'taxonomy', 'term_id'], $terms, ['tenant_id', 'taxonomy', 'id'], 'termables_tenant_term_foreign');
        $schema->enableForeignKeyConstraints();
    }

    /** Remove final constraints while retaining expanded adoption data. */
    public function down(): void
    {
        $schema = Schema::connection(TaxonomyConfiguration::connection());
        $terms = TaxonomyConfiguration::table(TaxonomyTables::Terms, TaxonomyTables::Terms);
        $translations = TaxonomyConfiguration::table(TaxonomyTables::I18n, TaxonomyTables::I18n);
        $attachments = TaxonomyConfiguration::table(TaxonomyTables::Termables, TaxonomyTables::Termables);
        foreach ([
            [$terms, ['tenant_id', 'taxonomy', 'parent_id']],
            [$translations, ['tenant_id', 'term_id']],
            [$attachments, ['tenant_id', 'taxonomy', 'term_id']],
        ] as [$table, $columns]) {
            $this->dropForeign($schema, $table, $columns);
        }
        foreach ([
            [$terms, 'terms_tenant_sibling_slug_unique', true],
            [$terms, 'terms_tenant_taxonomy_id_unique', true],
            [$terms, 'terms_tenant_id_unique', true],
            [$terms, 'terms_tenant_tree_index', false],
            [$translations, 'terms_i18n_tenant_locale_unique', true],
            [$attachments, 'termables_tenant_owner_unique', true],
            [$attachments, 'termables_tenant_owner_tax_pos_index', false],
        ] as [$table, $name, $unique]) {
            $this->dropIndex($schema, $table, $name, $unique);
        }
        $this->unique($schema, $terms, ['taxonomy', 'parent_key', 'slug'], 'terms_sibling_slug_unique');
        $this->unique($schema, $translations, ['term_id', 'locale'], 'terms_i18n_owner_locale_unique');
        $this->unique($schema, $attachments, ['term_id', 'termable_id', 'termable_type'], 'termables_owner_unique_legacy');
        $this->foreign($schema, $terms, ['taxonomy', 'parent_id'], $terms, ['taxonomy', 'id'], 'terms_taxonomy_parent_foreign', false);
        $this->foreign($schema, $translations, ['term_id'], $terms, ['id'], 'terms_i18n_term_id_foreign');
        $this->foreign($schema, $attachments, ['taxonomy', 'term_id'], $terms, ['taxonomy', 'id'], 'termables_taxonomy_term_foreign');
        foreach ([$terms, $translations, $attachments] as $table) {
            $this->required($schema, $table, 'tenant_id', true);
        }
    }

    /** Change one prepared tenant column nullability. */
    private function required(Builder $schema, string $table, string $column, bool $nullable): void
    {
        if ($schema->hasTable($table) && $schema->hasColumn($table, $column)) {
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->uuid($column)->nullable($nullable)->change());
        }
    }

    /** Add one unique index idempotently. */
    private function unique(Builder $schema, string $table, array $columns, string $name): void
    {
        if (! $schema->hasIndex($table, $name)) {
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->unique($columns, $name));
        }
    }

    /** Add one query index idempotently. */
    private function index(Builder $schema, string $table, array $columns, string $name): void
    {
        if (! $schema->hasIndex($table, $name)) {
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
        }
    }

    /** Add one composite foreign key idempotently. */
    private function foreign(Builder $schema, string $table, array $columns, string $parent, array $parentColumns, string $name, bool $cascade = true): void
    {
        if (! array_any($schema->getForeignKeys($table), static fn (array $foreign): bool => $foreign['name'] === $name)) {
            $schema->table($table, static function (Blueprint $blueprint) use ($columns, $parent, $parentColumns, $name, $cascade): void {
                $foreign = $blueprint->foreign($columns, $name)->references($parentColumns)->on($parent);
                $cascade ? $foreign->cascadeOnDelete() : $foreign->restrictOnDelete();
            });
        }
    }

    /** Drop one named foreign key when present. */
    private function dropForeign(Builder $schema, string $table, string|array $identifier): void
    {
        if ($schema->hasTable($table) && array_any(
            $schema->getForeignKeys($table),
            static fn (array $foreign): bool => is_string($identifier)
                ? ($foreign['name'] ?? null) === $identifier
                : ($foreign['columns'] ?? []) === $identifier,
        )) {
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->dropForeign($identifier));
        }
    }

    /** Drop every foreign key matching one exact local column set. */
    private function dropForeignColumns(Builder $schema, string $table, array $columns): void
    {
        if (! $schema->hasTable($table)) {
            return;
        }

        $expected = $columns;
        sort($expected);

        foreach ($schema->getForeignKeys($table) as $foreign) {
            $actual = $foreign['columns'];
            sort($actual);

            if ($actual === $expected) {
                $name = $foreign['name'] ?? null;
                $identifier = is_string($name) ? $name : $columns;
                $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->dropForeign($identifier));
            }
        }
    }

    /** Drop one named index when present. */
    private function dropIndex(Builder $schema, string $table, string $name, bool $unique): void
    {
        if ($schema->hasTable($table) && $schema->hasIndex($table, $name)) {
            $schema->table($table, static fn (Blueprint $blueprint) => $unique ? $blueprint->dropUnique($name) : $blueprint->dropIndex($name));
        }
    }

    /** Drop the unique index matching an exact column set on configured aliases. */
    private function dropUniqueColumns(Builder $schema, string $table, array $columns): void
    {
        $expected = $columns;
        sort($expected);
        foreach ($schema->getIndexes($table) as $index) {
            $actual = $index['columns'];
            sort($actual);
            if ($index['unique'] === true && $actual === $expected) {
                $name = $index['name'];
                $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->dropUnique($name));
            }
        }
    }
};
