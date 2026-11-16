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
     * Create the latest reviewed provider proposal per owner, taxonomy and source.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $tableName = TaxonomyTables::get(TaxonomyTables::MembershipMatches);

        if ($schema->hasTable($tableName)) {
            throw new LogicException('Existing package table is not owned by this migration. Run nvl:doctor --strict and use nvl:schema:upgrade for a verified legacy installation.');
        }

        $schema->create($tableName, static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('owner_type', 100);
            $table->string('owner_id', 191);
            $table->string('taxonomy', 64);
            $table->string('source', 64);
            $table->json('proposal');
            $table->string('mode', 16);
            $table->timestamp('matched_at', 6);

            $table->unique(['owner_type', 'owner_id', 'taxonomy', 'source'], 'nvl_taxonomy_membership_matches_identity');
        });
    }

    /**
     * Drop the membership matches table.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists(TaxonomyTables::get(TaxonomyTables::MembershipMatches));
    }
};
