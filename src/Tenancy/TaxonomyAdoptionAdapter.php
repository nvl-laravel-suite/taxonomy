<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Str;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Contracts\TenantAdoptionMetadataValidator;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Tenancy\Services\TenantAdoptionMappings;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;
use stdClass;

/** Owns Taxonomy's reviewed expansion, split-tree backfill, and final constraints. */
final readonly class TaxonomyAdoptionAdapter implements TenantAdoptionAdapter, TenantAdoptionMetadataValidator
{
    /** Create the package-owned Taxonomy adoption boundary. */
    public function __construct(
        private Migrator $migrator,
        private TenantAdoptionMappings $mappings,
        private EffectiveTenantConnection $connections,
        private TaxonomyOwnerRegistry $owners,
        private TenantResourceRegistry $resourcesRegistry,
    ) {}

    /** Validate exact optional reviewed split metadata for one legacy term. */
    public function validateAssignment(TenantAssignment $assignment): void
    {
        if ($assignment->resource !== 'taxonomy.terms') {
            if ($assignment->metadata !== []) {
                throw new TenantConfigurationInvalid('Only Taxonomy terms accept adoption metadata.');
            }

            return;
        }
        $unknown = array_diff(array_keys($assignment->metadata), ['splits', 'source_disposition']);
        $splits = $assignment->metadata['splits'] ?? [];
        if ($unknown !== [] || ! is_array($splits) || count($splits) > 100
            || ($splits !== [] && ($assignment->metadata['source_disposition'] ?? null) !== 'retain-primary')) {
            throw new TenantConfigurationInvalid('Taxonomy split metadata is invalid.');
        }
        $tenants = [$assignment->tenantId->value => true];
        $destinations = [$assignment->recordId => true];
        foreach ($splits as $split) {
            if (! is_array($split) || array_diff(array_keys($split), ['tenant_id', 'destination_id']) !== [] || count($split) !== 2
                || ! is_string($split['tenant_id']) || ! Str::isUuid($split['tenant_id'])
                || ! is_string($split['destination_id']) || ! Str::isUuid($split['destination_id'])
                || isset($tenants[$split['tenant_id']]) || isset($destinations[$split['destination_id']])) {
                throw new TenantConfigurationInvalid('Taxonomy split identities must be unique canonical UUIDs.');
            }
            $tenants[$split['tenant_id']] = true;
            $destinations[$split['destination_id']] = true;
        }
    }

    /** @return list<string> */
    public function resources(): array
    {
        return ['taxonomy.terms', 'taxonomy.attachments', 'taxonomy.translations'];
    }

    /** Apply only the nullable ownership expansion. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $this->assertConnection($plan);
        $path = dirname(__DIR__, 2).'/database/tenancy/2026_09_16_120001_expand_taxonomy_tenant_ownership.php';
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([$path], ['force' => true]));
    }

    /** Backfill one bounded assignment batch, then reconcile copied graph parents. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $this->assertConnection($plan);
        $batch = $this->mappings->assignments($plan, 'taxonomy.terms', $cursor, $limit);
        if ($batch !== []) {
            foreach ($batch as $assignment) {
                $this->connection($plan)->transaction(fn () => $this->backfillTerm($plan, $assignment));
            }
            $last = $batch[array_key_last($batch)];

            return new TenantBackfillResult($last->recordId, count($batch));
        }
        $this->connection($plan)->transaction(fn () => $this->reconcileGraph($plan));

        return new TenantBackfillResult(null, 0);
    }

    /**
     * Verify bounded ownership, canonical owners, and complete split graph state.
     *
     * @phpstan-impure
     */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $this->assertConnection($plan);
        $connection = $this->connection($plan);
        $schema = $connection->getSchemaBuilder();
        $terms = $this->table(TaxonomyTables::Terms);
        $translations = $this->table(TaxonomyTables::I18n);
        $attachments = $this->table(TaxonomyTables::Termables);
        $copies = $this->table(TaxonomyTables::TenantAdoptionCopies);
        $errors = [];
        foreach ([$terms, $translations, $attachments] as $table) {
            if (! $schema->hasTable($table) || ! $schema->hasColumn($table, 'tenant_id')) {
                $errors[] = $table.'.tenant_id';
            }
        }
        if ($schema->hasTable($terms) && $connection->table($terms)->whereNull('tenant_id')->exists()) {
            $errors[] = 'taxonomy.terms.unmapped';
        }
        if ($schema->hasTable($translations) && $connection->table($translations)->whereNull('tenant_id')->exists()) {
            $errors[] = 'taxonomy.translations.unmapped';
        }
        if ($schema->hasTable($attachments) && $connection->table($attachments)->whereNull('tenant_id')->exists()) {
            $errors[] = 'taxonomy.attachments.unmapped';
        }
        if ($schema->hasTable($translations) && $connection->table($translations.' as child')
            ->join($terms.' as parent', 'parent.id', '=', 'child.term_id')
            ->whereColumn('child.tenant_id', '!=', 'parent.tenant_id')->exists()) {
            $errors[] = 'taxonomy.translations.ownership';
        }
        if ($schema->hasTable($attachments) && $connection->table($attachments.' as child')
            ->join($terms.' as parent', 'parent.id', '=', 'child.term_id')
            ->whereColumn('child.tenant_id', '!=', 'parent.tenant_id')->exists()) {
            $errors[] = 'taxonomy.attachments.ownership';
        }
        if ($schema->hasTable($terms) && $connection->table($terms.' as child')
            ->join($terms.' as parent', 'parent.id', '=', 'child.parent_id')
            ->whereColumn('child.tenant_id', '!=', 'parent.tenant_id')->exists()) {
            $errors[] = 'taxonomy.terms.parent_ownership';
        }
        if ($schema->hasTable($copies) && $connection->table($copies)
            ->where('adoption_run_id', $plan->id)->where('status', '!=', 'committed')->exists()) {
            $errors[] = 'taxonomy.terms.copy_incomplete';
        }
        if ($schema->hasTable($attachments)) {
            $connection->table($attachments)->orderBy('id')->chunkById(100, function ($rows) use (&$errors): void {
                foreach ($rows as $attachment) {
                    try {
                        if ($this->attachmentOwnerTenant($attachment) !== $attachment->tenant_id) {
                            $errors[] = 'taxonomy.attachments.owner_ownership';
                        }
                    } catch (TenantBoundaryViolation|TenantConfigurationInvalid) {
                        $errors[] = 'taxonomy.attachments.owner_unavailable';
                    }
                }
            });
        }

        return new TenantVerification(array_slice(array_values(array_unique($errors)), 0, 100));
    }

    /** Apply final constraints only after verification succeeds. */
    public function activate(TenantAdoptionPlan $plan): void
    {
        $this->assertVerified($plan, 'Taxonomy tenant schema did not verify before activation.');
        $path = dirname(__DIR__, 2).'/database/tenancy/2026_09_16_120002_constrain_taxonomy_tenant_ownership.php';
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([$path], ['force' => true]));
        $this->assertVerified($plan, 'Taxonomy tenant schema did not verify after activation.');
    }

    /** Require a fresh persisted verification at one activation checkpoint. */
    private function assertVerified(TenantAdoptionPlan $plan, string $message): void
    {
        if ($this->verify($plan)->errors !== []) {
            throw new TenantBoundaryViolation($message);
        }
    }

    /** Persist the primary assignment, reviewed copies, locale rows, and owner rewrites. */
    private function backfillTerm(TenantAdoptionPlan $plan, TenantAssignment $assignment): void
    {
        $this->validateAssignment($assignment);
        $connection = $this->connection($plan);
        $terms = $this->table(TaxonomyTables::Terms);
        $translations = $this->table(TaxonomyTables::I18n);
        $attachments = $this->table(TaxonomyTables::Termables);
        $source = $connection->table($terms)->where('id', $assignment->recordId)->first();
        if (! $source instanceof stdClass) {
            throw new TenantBoundaryViolation('A reviewed Taxonomy term is unavailable.');
        }
        $destinations = $this->destinations($assignment);
        foreach ($destinations as $tenant => $destinationId) {
            $this->recordCopy($plan, $assignment->recordId, $tenant, $destinationId);
            if ($destinationId === $assignment->recordId) {
                $connection->table($terms)->where('id', $assignment->recordId)->update(['tenant_id' => $tenant]);
                $connection->table($translations)->where('term_id', $assignment->recordId)->update(['tenant_id' => $tenant]);
            } else {
                $this->copyTerm($source, $destinationId, $tenant);
            }
            $connection->table($this->table(TaxonomyTables::TenantAdoptionCopies))
                ->where('adoption_run_id', $plan->id)->where('source_id', $assignment->recordId)
                ->where('tenant_id', $tenant)->update(['status' => 'committed', 'updated_at' => now()]);
        }
        foreach ($connection->table($attachments)->where('term_id', $assignment->recordId)->orderBy('id')->get() as $attachment) {
            $tenant = $this->attachmentOwnerTenant($attachment);
            $destination = $destinations[$tenant] ?? null;
            if (! is_string($destination)) {
                throw new TenantBoundaryViolation('A reviewed Taxonomy split does not cover every attached owner.');
            }
            $connection->table($attachments)->where('id', $attachment->id)->update([
                'tenant_id' => $tenant,
                'term_id' => $destination,
                'updated_at' => now(),
            ]);
        }
    }

    /** Clone one structural row and its complete locale map. */
    private function copyTerm(stdClass $source, string $destinationId, string $tenant): void
    {
        $connection = $this->connectionForModel();
        $terms = $this->table(TaxonomyTables::Terms);
        $translations = $this->table(TaxonomyTables::I18n);
        $existing = $connection->table($terms)->where('id', $destinationId)->first();
        if ($existing !== null) {
            if ($existing->tenant_id !== $tenant || $existing->taxonomy !== $source->taxonomy || $existing->slug !== $source->slug) {
                throw new TenantBoundaryViolation('A reviewed Taxonomy destination collides with another term.');
            }

            return;
        }
        $connection->table($terms)->insert([
            'id' => $destinationId,
            'tenant_id' => $tenant,
            'taxonomy' => $source->taxonomy,
            'parent_id' => $source->parent_id,
            'parent_key' => $source->parent_key,
            'slug' => $source->slug,
            'position' => $source->position,
            'meta' => $source->meta,
            'revision' => $source->revision,
            'created_at' => $source->created_at,
            'updated_at' => $source->updated_at,
        ]);
        foreach ($connection->table($translations)->where('term_id', $source->id)->get() as $translation) {
            $connection->table($translations)->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant,
                'term_id' => $destinationId,
                'locale' => $translation->locale,
                'name' => $translation->name,
                'description' => $translation->description,
                'created_at' => $translation->created_at,
                'updated_at' => $translation->updated_at,
            ]);
        }
    }

    /** Repoint every copied parent to the same tenant's reviewed parent copy. */
    private function reconcileGraph(TenantAdoptionPlan $plan): void
    {
        $connection = $this->connection($plan);
        $terms = $this->table(TaxonomyTables::Terms);
        $copies = $this->table(TaxonomyTables::TenantAdoptionCopies);
        foreach ($connection->table($copies)->where('adoption_run_id', $plan->id)->orderBy('source_id')->orderBy('tenant_id')->get() as $copy) {
            $source = $connection->table($terms)->where('id', $copy->source_id)->first();
            if (! $source instanceof stdClass) {
                throw new TenantBoundaryViolation('A reviewed Taxonomy source disappeared during adoption.');
            }
            $parentId = null;
            if (is_string($source->parent_id)) {
                $parentId = $connection->table($copies)->where('adoption_run_id', $plan->id)
                    ->where('source_id', $source->parent_id)->where('tenant_id', $copy->tenant_id)->value('destination_id');
                if (! is_string($parentId)) {
                    throw new TenantBoundaryViolation('A reviewed Taxonomy split lacks a required ancestor copy.');
                }
            }
            $connection->table($terms)->where('id', $copy->destination_id)->update([
                'parent_id' => $parentId,
                'parent_key' => $parentId ?? '__root__',
                'updated_at' => now(),
            ]);
        }
    }

    /** @return array<string, string> */
    private function destinations(TenantAssignment $assignment): array
    {
        $destinations = [$assignment->tenantId->value => $assignment->recordId];
        $splits = $assignment->metadata['splits'] ?? [];
        if (! is_array($splits)) {
            throw new TenantConfigurationInvalid('Taxonomy split metadata is invalid.');
        }
        foreach ($splits as $split) {
            if (! is_array($split) || ! is_string($split['tenant_id'] ?? null) || ! is_string($split['destination_id'] ?? null)) {
                throw new TenantConfigurationInvalid('Taxonomy split metadata is invalid.');
            }
            $destinations[$split['tenant_id']] = $split['destination_id'];
        }
        ksort($destinations);

        return $destinations;
    }

    /** Persist one idempotent reviewed copy-ledger row. */
    private function recordCopy(TenantAdoptionPlan $plan, string $sourceId, string $tenant, string $destinationId): void
    {
        $table = $this->table(TaxonomyTables::TenantAdoptionCopies);
        $connection = $this->connection($plan);
        $row = $connection->table($table)->where('adoption_run_id', $plan->id)
            ->where('source_id', $sourceId)->where('tenant_id', $tenant)->first();
        if ($row !== null) {
            if ($row->destination_id !== $destinationId) {
                throw new TenantBoundaryViolation('A reviewed Taxonomy copy destination changed.');
            }

            return;
        }
        $connection->table($table)->insert([
            'adoption_run_id' => $plan->id,
            'source_id' => $sourceId,
            'tenant_id' => $tenant,
            'destination_id' => $destinationId,
            'status' => 'prepared',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Resolve one attachment's canonical registered owner tenant. */
    private function attachmentOwnerTenant(stdClass $attachment): string
    {
        if (! is_string($attachment->termable_type) || (! is_string($attachment->termable_id) && ! is_int($attachment->termable_id))) {
            throw new TenantBoundaryViolation('A Taxonomy attachment owner identity is invalid.');
        }
        $modelClass = $this->owners->all()[$attachment->termable_type] ?? null;
        if (! is_string($modelClass)) {
            throw new TenantBoundaryViolation('A Taxonomy attachment owner type is not registered.');
        }
        $model = new $modelClass;
        $this->resourcesRegistry->forModel($model);
        if ($model->getConnection() !== $this->connectionForModel()) {
            throw new TenantConfigurationInvalid('Taxonomy owner adoption requires one effective connection.');
        }
        $tenant = $model->newQueryWithoutScopes()->whereKey($attachment->termable_id)->toBase()->value('tenant_id');
        if (! is_string($tenant) || ! Str::isUuid($tenant)) {
            throw new TenantBoundaryViolation('A Taxonomy attachment owner lacks reviewed tenant ownership.');
        }

        return $tenant;
    }

    /** Require the package storage and adoption plan to share one connection object. */
    private function assertConnection(TenantAdoptionPlan $plan): void
    {
        $this->connections->assertCompatible([$plan->connection, TaxonomyConfiguration::connection()]);
        if ($this->connectionForModel()->getName() !== $plan->connection) {
            throw new TenantConfigurationInvalid('Taxonomy adoption uses a different effective connection.');
        }
    }

    /** Resolve package storage from the reviewed plan. */
    private function connection(TenantAdoptionPlan $plan): Connection
    {
        $this->assertConnection($plan);

        return $this->connectionForModel();
    }

    /** Resolve Taxonomy's configured connection without trusting a raw alias. */
    private function connectionForModel(): Connection
    {
        return (new Term)->getConnection();
    }

    /** Resolve one configured package table. */
    private function table(string $table): string
    {
        return TaxonomyConfiguration::table($table, $table);
    }
}
