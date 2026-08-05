<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the structural taxonomy term table.
     */
    public function up(): void
    {
        $tableNames = config('taxonomy.table_names', ['terms' => 'terms', 'termables' => 'termables']);

        $schema = Schema::connection(config('taxonomy.storage.connection'));
        $tableName = (string) $tableNames['terms'];

        if ($schema->hasTable($tableName)) {
            return;
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
                ->on((string) config('taxonomy.table_names.terms', 'terms'))
                ->restrictOnDelete();
        });
    }

    /**
     * Drop the structural taxonomy term table.
     */
    public function down(): void
    {
        $tableNames = config('taxonomy.table_names', ['terms' => 'terms', 'termables' => 'termables']);
        Schema::connection(config('taxonomy.storage.connection'))
            ->dropIfExists((string) $tableNames['terms']);
    }
};
