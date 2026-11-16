<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Config\PackageStorage;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return PackageStorage::connection('taxonomy');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table((string) TaxonomyTables::get(TaxonomyTables::Termables), static function (Blueprint $table): void {
            $table->unsignedInteger('member_position')->nullable();
            $table->string('membership_source', 64)->nullable();
            $table->index(['term_id', 'member_position', 'id'], 'nvl_taxonomy_term_members_order');
        });
    }

    public function down(): void
    {
        $table = (string) TaxonomyTables::get(TaxonomyTables::Termables);
        if (Schema::connection($this->getConnection())->getConnection()->table($table)
            ->whereNotNull('member_position')->orWhereNotNull('membership_source')->exists()) {
            throw new LogicException('Taxonomy member positions and provenance must be backed up and cleared before rollback.');
        }
        Schema::connection($this->getConnection())->table($table, static function (Blueprint $table): void {
            $table->dropIndex('nvl_taxonomy_term_members_order');
            $table->dropColumn(['member_position', 'membership_source']);
        });
    }
};
