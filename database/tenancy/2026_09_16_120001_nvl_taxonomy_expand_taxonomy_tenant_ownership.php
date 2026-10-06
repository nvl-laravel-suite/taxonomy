<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Config\PackageStorage;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;

return new class extends Migration
{
    /** Use the effective package connection for Laravel's migration transaction. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('taxonomy');
    }

    /** Add nullable tenant columns and the reviewed split-copy ledger. */
    public function up(): void
    {
        foreach ([TaxonomyTables::get(TaxonomyTables::Terms), TaxonomyTables::get(TaxonomyTables::I18n), TaxonomyTables::get(TaxonomyTables::Termables)] as $table) {
            $this->expand(TaxonomyConfiguration::table($table, $table), static function (Blueprint $blueprint) use ($table): void {
                $blueprint->uuid('tenant_id')->nullable()->index('taxonomy_'.$table.'_tenant_idx');
            });
        }

        $copies = TaxonomyConfiguration::table(TaxonomyTables::get(TaxonomyTables::TenantAdoptionCopies), TaxonomyTables::get(TaxonomyTables::TenantAdoptionCopies));
        $schema = Schema::connection(TaxonomyConfiguration::connection());
        if (! $schema->hasTable($copies)) {
            $schema->create($copies, static function (Blueprint $table): void {
                $table->uuid('adoption_run_id');
                $table->uuid('source_id');
                $table->uuid('tenant_id');
                $table->uuid('destination_id');
                $table->string('status', 32);
                $table->timestamps();
                $table->primary(['adoption_run_id', 'source_id', 'tenant_id'], 'term_adoption_copies_primary');
                $table->unique(['adoption_run_id', 'destination_id'], 'term_adoption_destination_unique');
            });
        }
    }

    /** Remove only the nullable adoption expansion. */
    public function down(): void
    {
        $schema = Schema::connection(TaxonomyConfiguration::connection());
        $schema->dropIfExists(TaxonomyConfiguration::table(TaxonomyTables::get(TaxonomyTables::TenantAdoptionCopies), TaxonomyTables::get(TaxonomyTables::TenantAdoptionCopies)));
        foreach ([TaxonomyTables::get(TaxonomyTables::Terms), TaxonomyTables::get(TaxonomyTables::I18n), TaxonomyTables::get(TaxonomyTables::Termables)] as $table) {
            $name = TaxonomyConfiguration::table($table, $table);
            if ($schema->hasTable($name) && $schema->hasColumn($name, 'tenant_id')) {
                $schema->table($name, static fn (Blueprint $blueprint) => $blueprint->dropColumn('tenant_id'));
            }
        }
    }

    /** Add a nullable ownership column only to an existing legacy table. */
    private function expand(string $table, Closure $callback): void
    {
        $schema = Schema::connection(TaxonomyConfiguration::connection());
        if ($schema->hasTable($table) && ! $schema->hasColumn($table, 'tenant_id')) {
            $schema->table($table, $callback);
        }
    }
};
