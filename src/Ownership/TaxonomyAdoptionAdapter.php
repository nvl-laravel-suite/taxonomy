<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Ownership;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Services\OwnershipAdoptionStore;
use Nvl\Ownership\Services\OwnershipDatabaseConstraints;
use Nvl\Ownership\Services\OwnershipResourceBackfill;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipVerification;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Models\TermTranslation;

/**
 * Adopts exact reviewed Taxonomy records without guessing primary owners from associations.
 *
 * @internal
 */
final readonly class TaxonomyAdoptionAdapter implements OwnershipAdoptionAdapter
{
    public function __construct(
        private OwnershipResourceRegistry $resources,
        private OwnershipAdoptionStore $mappings,
        private OwnershipSchema $schema,
        private OwnershipDatabaseConstraints $constraints,
    ) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['taxonomy.terms', 'taxonomy.translations', 'taxonomy.attachments'];
    }

    /** Prepare additive partitions and replace only the package's known global receipt identity. */
    public function prepare(OwnershipAdoptionPlan $plan): void
    {
        $root = new ($this->resources->get('taxonomy.terms')->model);
        $connection = $root->getConnection();
        $schema = $connection->getSchemaBuilder();
        foreach ($this->resources() as $key) {
            $model = new ($this->resources->get($key)->model);
            foreach (['tenant_id', 'ownership_key'] as $column) {
                if ($schema->hasColumn($model->getTable(), $column)) {
                    $schema->table($model->getTable(), static fn (Blueprint $table) => ($column === 'tenant_id' ? $table->uuid($column) : $table->string($column, 44))->nullable()->change());
                }
            }
            $this->schema->prepare($key);
        }
        foreach ([['taxonomy.terms', ['taxonomy', 'parent_id'], 'taxonomy.terms', ['taxonomy', 'id']], ['taxonomy.translations', ['term_id'], 'taxonomy.terms', ['id']], ['taxonomy.attachments', ['taxonomy', 'term_id'], 'taxonomy.terms', ['taxonomy', 'id']]] as [$childKey,$columns,$parentKey,$parentColumns]) {
            $child = new ($this->resources->get($childKey)->model);
            $parent = new ($this->resources->get($parentKey)->model);
            $found = false;
            foreach ($schema->getForeignKeys($child->getTable()) as $foreign) {
                if ($foreign['columns'] === $columns && $foreign['foreign_table'] === $parent->getConnection()->getTablePrefix().$parent->getTable() && $foreign['foreign_columns'] === $parentColumns) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $name = 'taxonomy_native_'.substr(hash('sha256', $child->getTable().implode(':', $columns)), 0, 24);
                $schema->table($child->getTable(), static fn (Blueprint $table) => $table->foreign($columns, $name)->references($parentColumns)->on($parent->getTable())->restrictOnDelete());
            }
        }
        foreach ($schema->getIndexes($root->getTable()) as $index) {
            if ($index['unique'] && in_array($index['columns'], [['taxonomy', 'parent_key', 'slug'], ['tenant_id', 'taxonomy', 'parent_key', 'slug']], true)) {
                $schema->table($root->getTable(), static fn (Blueprint $table) => $table->dropUnique($index['name']));
            }
        }
        $this->unique($root, ['owner_scope_key', 'taxonomy', 'parent_key', 'slug'], 'taxonomy_owner_sibling_slug_unique');
        $this->unique(new (TermTranslation::class), ['owner_scope_key', 'term_id', 'locale'], 'taxonomy_owner_locale_unique');
        $this->unique(new (Termable::class), ['owner_scope_key', 'term_id', 'termable_type', 'termable_id'], 'taxonomy_owner_attachment_unique');
    }

    /** @param list<string> $columns */
    private function unique(Model $model, array $columns, string $name): void
    {
        $schema = $model->getConnection()->getSchemaBuilder();
        foreach ($schema->getIndexes($model->getTable()) as $index) {
            if ($index['unique'] && $index['columns'] === $columns) {
                return;
            }
        }
        $schema->table($model->getTable(), static fn (Blueprint $table) => $table->unique($columns, $name));
    }

    /** Backfill bounded actual records from explicit immutable per-record mappings. */
    public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        return (new OwnershipResourceBackfill($this->resources, $this->mappings))->run($plan, $this->resources(), $cursor, $limit, 'Taxonomy');
    }

    public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
    {
        foreach ($this->resources() as $key) {
            $this->schema->verify($key);
        }

        $root = new ($this->resources->get('taxonomy.terms')->model);
        $connection = $root->getConnection();
        $table = $root->getTable();
        $bad = $connection->table($table.' as child')->leftJoin($table.' as parent', 'parent.id', '=', 'child.parent_id')->whereNotNull('child.parent_id')->where(static fn (Builder $q) => $q->whereNull('parent.id')->orWhereColumn('parent.owner_scope_key', '!=', 'child.owner_scope_key')->orWhereColumn('parent.taxonomy', '!=', 'child.taxonomy'))->exists();
        $attachment = new ($this->resources->get('taxonomy.attachments')->model);
        $bad = $bad || $connection->table($attachment->getTable().' as child')->leftJoin($table.' as parent', 'parent.id', '=', 'child.term_id')->where(static fn (Builder $q) => $q->whereNull('parent.id')->orWhereColumn('parent.owner_scope_key', '!=', 'child.owner_scope_key')->orWhereColumn('parent.taxonomy', '!=', 'child.taxonomy'))->exists();
        if ($bad) {
            throw new OwnershipConfigurationInvalid('Taxonomy parents and attachments must retain their canonical owner and vocabulary.');
        }

        return new OwnershipVerification([]);
    }

    public function activate(OwnershipAdoptionPlan $plan): void
    {
        foreach ($this->resources() as $key) {
            $this->schema->constrain($key);
        }
        $root = new ($this->resources->get('taxonomy.terms')->model);
        $attachment = new ($this->resources->get('taxonomy.attachments')->model);
        $this->constraints->reference($root->getConnection(), $root->getTable(), 'parent_id', $root->getTable(), 'id', true);
        $this->constraints->reference($root->getConnection(), $attachment->getTable(), 'term_id', $root->getTable(), 'id');
        $targets = [];
        foreach ($this->resources->get('taxonomy.attachments')->parents() as $key) {
            $parent = new ($this->resources->get($key)->model);
            $targets[] = ['type' => $parent->getMorphClass(), 'table' => $parent->getTable(), 'key' => $parent->getKeyName()];
        }
        if ($targets === []) {
            throw new OwnershipConfigurationInvalid('Owned Taxonomy attachments require canonical host resources.');
        }
        $this->constraints->polymorphicReference($root->getConnection(), $attachment->getTable(), 'termable_type', 'termable_id', $targets);
    }
}
