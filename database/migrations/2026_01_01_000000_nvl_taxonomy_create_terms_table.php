<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Config\PackageStorage;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;

return new class extends Migration
{
    /** Use the effective package connection for Laravel's migration transaction. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('taxonomy');
    }

    /**
     * Create the structural taxonomy term table.
     */
    public function up(): void
    {

        $schema = Schema::connection(PackageStorage::connection('taxonomy'));
        $tableName = (string) TaxonomyTables::get(TaxonomyTables::Terms);

        if ($schema->hasTable($tableName)) {
            throw new LogicException('Existing package table is not owned by this migration. Run nvl:doctor --strict and use nvl:schema:upgrade for a verified legacy installation.');
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('taxonomy', 64);
            $table->uuid('parent_id')->nullable();
            $table->string('parent_key', 36)->default('__root__');
            $table->string('slug', 191);
            $table->unsignedInteger('position')->default(0);
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamps();

            $table->unique(['taxonomy', 'parent_key', 'slug'], 'terms_sibling_slug_unique');
            $table->unique(['taxonomy', 'id'], 'terms_taxonomy_id_unique');
            $table->index(['taxonomy', 'slug'], 'terms_taxonomy_slug_index');
            $table->index(['taxonomy', 'parent_id', 'position']);
            $table->foreign(['taxonomy', 'parent_id'], 'terms_taxonomy_parent_foreign')
                ->references(['taxonomy', 'id'])
                ->on((string) config('nvl-taxonomy.table_names.terms', TaxonomyTables::get(TaxonomyTables::Terms)))
                ->restrictOnDelete();
        });
    }

    /**
     * Drop the structural taxonomy term table.
     */
    public function down(): void
    {
        Schema::connection(PackageStorage::connection('taxonomy'))
            ->dropIfExists((string) TaxonomyTables::get(TaxonomyTables::Terms));
    }
};
