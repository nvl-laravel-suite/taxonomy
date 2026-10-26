<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;
use RuntimeException;

/** Owns the empty canonical Taxonomy owner fixture schema. */
final readonly class TenancyOwnerAdoptionAdapter implements TenantAdoptionAdapter
{
    /** @return list<string> */
    public function resources(): array
    {
        return ['test.taxonomy-owners'];
    }

    /** Create the owner table with direct tenant identity. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $schema = $this->connection()->getSchemaBuilder();
        if (! $schema->hasTable((new Post)->getTable())) {
            $schema->create((new Post)->getTable(), static function (Blueprint $table): void {
                $table->id();
                $table->uuid('tenant_id');
                $table->string('title');
                $table->timestamps();
                $table->unique(['tenant_id', 'id'], 'taxonomy_posts_tenant_id_unique');
            });
        }
    }

    /** Reject any fixture owner without its authoritative tenant. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        if ($this->connection()->table((new Post)->getTable())->whereNull('tenant_id')->exists()) {
            throw new RuntimeException('Taxonomy owner fixture adoption found an unmapped owner.');
        }

        return new TenantBackfillResult(null, 0);
    }

    /** Verify owner columns and partition uniqueness. */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $schema = $this->connection()->getSchemaBuilder();
        $table = (new Post)->getTable();
        $errors = [];
        if (! $schema->hasColumns($table, ['id', 'tenant_id', 'title', 'created_at', 'updated_at'])) {
            $errors[] = 'test.taxonomy-owners.columns';
        }
        if (! $schema->hasIndex($table, ['tenant_id', 'id'], 'unique')) {
            $errors[] = 'test.taxonomy-owners.identity';
        }

        return new TenantVerification($errors);
    }

    /** The fixture schema is fully constrained during preparation. */
    public function activate(TenantAdoptionPlan $plan): void {}

    /** Resolve the fixture owner's effective connection. */
    private function connection(): Connection
    {
        return (new Post)->getConnection();
    }
}
