<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableNames = config('taxonomy.table_names', [
            'terms' => 'terms',
            'terms_i18n' => 'terms_i18n',
        ]);

        $schema = Schema::connection(config('taxonomy.storage.connection'));
        $tableName = (string) $tableNames['terms_i18n'];

        if ($schema->hasTable($tableName)) {
            return;
        }

        $schema->create($tableName, function (Blueprint $table) use ($tableNames): void {
            $table->uuid('id')->primary();
            $table->uuid('term_id');
            $table->string('locale', 35);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['term_id', 'locale'], 'terms_i18n_owner_locale_unique');
            $table->foreign('term_id')
                ->references('id')
                ->on($tableNames['terms'])
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableNames = config('taxonomy.table_names', [
            'terms_i18n' => 'terms_i18n',
        ]);

        Schema::connection(config('taxonomy.storage.connection'))
            ->dropIfExists((string) $tableNames['terms_i18n']);
    }
};
