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
     * Run the migrations.
     */
    public function up(): void
    {

        $schema = Schema::connection(PackageStorage::connection('taxonomy'));
        $tableName = (string) TaxonomyTables::get(TaxonomyTables::I18n);

        if ($schema->hasTable($tableName)) {
            throw new LogicException('Existing package table is not owned by this migration. Run nvl:doctor --strict and use nvl:schema:upgrade for a verified legacy installation.');
        }

        $schema->create($tableName, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('term_id');
            $table->string('locale', 35);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['term_id', 'locale'], 'terms_i18n_owner_locale_unique');
            $table->foreign('term_id')
                ->references('id')
                ->on(TaxonomyTables::get(TaxonomyTables::Terms))
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

        Schema::connection(PackageStorage::connection('taxonomy'))
            ->dropIfExists((string) TaxonomyTables::get(TaxonomyTables::I18n));
    }
};
