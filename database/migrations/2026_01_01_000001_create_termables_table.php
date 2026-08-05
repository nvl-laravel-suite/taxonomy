<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the polymorphic taxonomy attachment table.
     */
    public function up(): void
    {
        $tableNames = config('taxonomy.table_names', ['terms' => 'terms', 'termables' => 'termables']);

        $schema = Schema::connection(config('taxonomy.storage.connection'));
        $tableName = (string) $tableNames['termables'];

        if ($schema->hasTable($tableName)) {
            return;
        }

        $schema->create($tableName, function (Blueprint $table) use ($tableNames): void {
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
                ->on($tableNames['terms'])
                ->cascadeOnDelete();
        });
    }

    /**
     * Drop the polymorphic taxonomy attachment table.
     */
    public function down(): void
    {
        $tableNames = config('taxonomy.table_names', ['terms' => 'terms', 'termables' => 'termables']);
        Schema::connection(config('taxonomy.storage.connection'))
            ->dropIfExists((string) $tableNames['termables']);
    }
};
