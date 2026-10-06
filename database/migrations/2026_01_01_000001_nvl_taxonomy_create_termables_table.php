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
     * Create the polymorphic taxonomy attachment table.
     */
    public function up(): void
    {

        $schema = Schema::connection(PackageStorage::connection('taxonomy'));
        $tableName = (string) TaxonomyTables::get(TaxonomyTables::Termables);

        if ($schema->hasTable($tableName)) {
            throw new LogicException('Existing package table is not owned by this migration. Run nvl:doctor --strict and use nvl:schema:upgrade for a verified legacy installation.');
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('term_id');
            $table->string('termable_type', 100);
            $table->string('termable_id', 191);
            $table->string('taxonomy', 64);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['term_id', 'termable_id', 'termable_type']);
            $table->index(['termable_type', 'termable_id', 'taxonomy', 'position'], 'termables_model_tax_pos_index');
            $table->index(['taxonomy', 'term_id']);
            $table->foreign(['taxonomy', 'term_id'], 'termables_taxonomy_term_foreign')
                ->references(['taxonomy', 'id'])
                ->on(TaxonomyTables::get(TaxonomyTables::Terms))
                ->cascadeOnDelete();
        });
    }

    /**
     * Drop the polymorphic taxonomy attachment table.
     */
    public function down(): void
    {
        Schema::connection(PackageStorage::connection('taxonomy'))
            ->dropIfExists((string) TaxonomyTables::get(TaxonomyTables::Termables));
    }
};
