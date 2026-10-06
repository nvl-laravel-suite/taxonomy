<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization;
use Nvl\Taxonomy\Exceptions\TaxonomyBatchReadException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/** Composes mandatory authorized attachment and structural term SQL before bounds. */
final readonly class TaxonomyBatchQueryBuilder
{
    /** Retain canonical ownership and registered vocabulary definitions. */
    public function __construct(private TenantBoundary $boundary, private TaxonomyRegistry $vocabularies) {}

    /**
     * Return the registered term model with retained scopes and nested private policy.
     *
     * @return Builder<Term>
     */
    public function terms(string $vocabulary, TaxonomyBatchAuthorization $policy, ?Model $host = null): Builder
    {
        $definition = $this->vocabularies->get($vocabulary);
        $prototype = new $definition->model;
        if ($prototype->getConnection() !== (new Term)->getConnection()) {
            throw new TaxonomyBatchReadException('Registered taxonomy terms must share the canonical package connection.');
        }
        $query = $this->boundary->query($prototype->newQuery()->withoutEagerLoads(), 'taxonomy.terms');
        TaxonomyBatchOwnerAdmission::exact($query, $prototype->qualifyColumn('taxonomy'), $vocabulary);
        $query->where(fn (Builder $nested) => $policy->scopeTerms($nested, [$vocabulary]));
        if ($host !== null) {
            $query->where(fn (Builder $nested) => $policy->scopeHostTerms($nested, $host, $vocabulary));
        }

        return $query;
    }

    /** Return canonical inherited attachment storage with its explicit boundary.
     * @return Builder<Termable>
     */
    public function attachments(): Builder
    {
        $query = Termable::query()->withoutEagerLoads();
        if (! in_array($query->getModel()->getConnection()->getDriverName(), ['sqlite', 'pgsql', 'mysql', 'mariadb'], true)) {
            throw new TaxonomyBatchReadException('Taxonomy batch reads require SQLite, PostgreSQL, MySQL or MariaDB window support.');
        }

        return $this->boundary->query($query, 'taxonomy.attachments');
    }

    /**
     * Restrict exact captured owner pairs and term-vocabulary tuples before ranking.
     *
     * @param  list<string>  $vocabularies
     * @return Builder<Termable>
     */
    public function batch(OwnerBatch $owners, array $vocabularies, TaxonomyBatchAuthorization $policy): Builder
    {
        $query = $this->attachments();
        $model = $query->getModel();
        $query->where(function (Builder $pairs) use ($owners, $model): void {
            foreach ($owners->identities() as $identity) {
                $pairs->orWhere(function (Builder $pair) use ($identity, $model): void {
                    TaxonomyBatchOwnerAdmission::exact($pair, $model->qualifyColumn('termable_type'), $identity->type);
                    TaxonomyBatchOwnerAdmission::exact($pair, $model->qualifyColumn('termable_id'), $identity->id);
                });
            }
        });
        $query->where(function (Builder $vocabularyQueries) use ($vocabularies, $model, $policy): void {
            foreach ($vocabularies as $vocabulary) {
                $vocabularyQueries->orWhere(function (Builder $vocabularyQuery) use ($vocabulary, $model, $policy): void {
                    TaxonomyBatchOwnerAdmission::exact($vocabularyQuery, $model->qualifyColumn('taxonomy'), $vocabulary);
                    $terms = $this->terms($vocabulary, $policy);
                    $vocabularyQuery->whereIn($model->qualifyColumn('term_id'), $terms->select($terms->getModel()->getQualifiedKeyName()));
                });
            }
        });
        $query->where(fn (Builder $nested) => $policy->scopeAttachments($nested, $owners, $vocabularies));

        return $query;
    }

    /**
     * Transfer at most 101 attachment facts for every exact owner-vocabulary group.
     *
     * @param  Builder<Termable>  $query
     */
    public function probe(Builder $query): QueryBuilder
    {
        $visible = $query->toBase();
        $model = $query->getModel();
        $driver = $query->getModel()->getConnection()->getDriverName();
        $columns = [];
        foreach (['id', 'term_id', 'termable_type', 'termable_id', 'taxonomy', 'position'] as $column) {
            $columns[] = $model->qualifyColumn($column);
        }
        $window = match ($driver) {
            'mysql', 'mariadb' => 'ROW_NUMBER() OVER (PARTITION BY BINARY nvl_visible_taxonomy.termable_type, BINARY nvl_visible_taxonomy.termable_id, BINARY nvl_visible_taxonomy.taxonomy ORDER BY nvl_visible_taxonomy.position, nvl_visible_taxonomy.term_id) AS nvl_taxonomy_rank',
            'pgsql' => 'ROW_NUMBER() OVER (PARTITION BY nvl_visible_taxonomy.termable_type COLLATE "C", nvl_visible_taxonomy.termable_id COLLATE "C", nvl_visible_taxonomy.taxonomy COLLATE "C" ORDER BY nvl_visible_taxonomy.position, nvl_visible_taxonomy.term_id) AS nvl_taxonomy_rank',
            default => 'ROW_NUMBER() OVER (PARTITION BY nvl_visible_taxonomy.termable_type COLLATE BINARY, nvl_visible_taxonomy.termable_id COLLATE BINARY, nvl_visible_taxonomy.taxonomy COLLATE BINARY ORDER BY nvl_visible_taxonomy.position, nvl_visible_taxonomy.term_id) AS nvl_taxonomy_rank',
        };
        $ranked = $visible->newQuery()->fromSub((clone $visible)->select($columns), 'nvl_visible_taxonomy')
            ->select('nvl_visible_taxonomy.*')->selectRaw($window);

        return $visible->newQuery()->fromSub($ranked, 'nvl_ranked_taxonomy')
            ->where('nvl_ranked_taxonomy.nvl_taxonomy_rank', '<=', 101)
            ->orderBy('nvl_ranked_taxonomy.nvl_taxonomy_rank')
            ->orderBy('nvl_ranked_taxonomy.term_id');
    }
}
