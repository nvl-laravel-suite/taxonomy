<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization;
use Nvl\Taxonomy\Exceptions\TaxonomyBatchReadException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Relations\ExactTaxonomyText;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/** Correlates registered visible terms without widening host ownership or selections. */
final readonly class TaxonomyHostQueryAdapter
{
    /** Retain canonical host admission and SQL authorization dependencies. */
    public function __construct(
        private TaxonomyBatchOwnerAdmission $owners,
        private TaxonomyBatchQueryBuilder $queries,
        private TaxonomyBatchAuthorization $policy,
        private TaxonomyRegistry $vocabularies,
    ) {}

    /**
     * Preserve any, all and exclusion semantics with bounded reference input.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @param  array<array-key, mixed>  $references
     * @return Builder<T>
     */
    public function filter(Builder $query, string $vocabulary, array $references, string $mode, ?TaxonomyBatchAuthorization $policy = null): Builder
    {
        $this->owners->assertVocabulary($query->getModel(), $vocabulary);
        $this->owners->assertHostQuery($query);
        if (! array_is_list($references)) {
            throw new InvalidArgumentException('Taxonomy term references must be a finite list.');
        }
        $values = [];
        foreach ($references as $reference) {
            if (! is_string($reference) && ! is_int($reference)) {
                throw new InvalidArgumentException('Taxonomy term references must be scalar identifiers or slugs.');
            }
            $values[(string) $reference] = true;
        }
        if (count($values) > TaxonomyConfiguration::positiveLimit('bulk_terms', 500)) {
            throw new InvalidArgumentException('Too many taxonomy terms were supplied.');
        }
        if ($values !== []) {
            $this->assertCorrelationShape($query->getModel(), $vocabulary);
        }
        $query = $this->owners->scope($query);
        $policy ??= $this->policy;
        if ($values === []) {
            return $mode === 'any' ? $query->whereRaw('1 = 0') : $query;
        }
        if ($mode === 'all') {
            foreach (array_keys($values) as $value) {
                $query->whereExists($this->matching($query->getModel(), $vocabulary, [(string) $value], $policy)->selectRaw('1')->toBase());
            }

            return $query;
        }
        $terms = $this->matching($query->getModel(), $vocabulary, array_map(strval(...), array_keys($values)), $policy)->selectRaw('1')->toBase();

        return $query->whereExists($terms, 'and', $mode === 'without');
    }

    /**
     * Admit a registered scoped root then traverse only bounded visible child sets.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function category(Builder $query, Term $category, bool $descendants = true, ?TaxonomyBatchAuthorization $policy = null): Builder
    {
        $this->owners->assertHostQuery($query);
        $identifier = $category->getRawOriginal($category->getKeyName());
        $vocabulary = $category->getRawOriginal('taxonomy');
        if (! $category->exists || ! is_string($identifier) || ! is_string($vocabulary)) {
            throw new InvalidArgumentException('Category filters require a persisted canonical vocabulary and term identifier.');
        }
        $this->owners->assertVocabulary($query->getModel(), $vocabulary);
        $this->assertCorrelationShape($query->getModel(), $vocabulary);
        $policy ??= $this->policy;
        $rootQuery = $this->queries->terms($vocabulary, $policy);
        if ($category->getConnection() !== $rootQuery->getConnection()
            || $category->getTable() !== $rootQuery->getModel()->getTable()) {
            throw new InvalidArgumentException('Category filters require canonical registered term storage.');
        }
        $root = $rootQuery->whereKey($identifier)->first();
        if (! $root instanceof Term) {
            throw new InvalidArgumentException('The category root is unavailable in the registered visible vocabulary.');
        }
        $ids = [$root->id];
        $seen = [$root->id => true];
        $frontier = $ids;
        $limit = TaxonomyConfiguration::positiveLimit('bulk_terms', 500);
        while ($descendants && $frontier !== []) {
            $children = $this->queries->terms($vocabulary, $policy);
            $model = $children->getModel();
            $rows = $children->whereIn($model->qualifyColumn('parent_id'), $frontier)
                ->orderBy($model->getQualifiedKeyName())->limit($limit - count($seen) + 1)
                ->get([$model->getQualifiedKeyName()]);
            $frontier = [];
            foreach ($rows as $child) {
                if (isset($seen[$child->id])) {
                    continue;
                }
                $seen[$child->id] = true;
                if (count($seen) > $limit) {
                    throw new TaxonomyBatchReadException('Category traversal exceeds the declared taxonomy term bound.');
                }
                $ids[] = $child->id;
                $frontier[] = $child->id;
            }
        }

        return $this->filter($query, $vocabulary, $ids, 'any', $policy);
    }

    /** Reject physical table shadowing before any ownership or term storage query. */
    private function assertCorrelationShape(Model $owner, string $vocabulary): void
    {
        $termClass = $this->vocabularies->get($vocabulary)->model;
        $tables = [(new $termClass)->getTable(), (new Termable)->getTable()];
        $ownerName = $this->correlationName($owner->getTable());
        foreach ($tables as $table) {
            if ($ownerName === $this->correlationName($table)) {
                throw new TaxonomyBatchReadException(
                    'Taxonomy host filters cannot safely correlate matching owner, registered vocabulary or attachment table names. Use ListOwnerTaxonomyTermsContract for bounded owner reads.',
                );
            }
        }
    }

    /** Compare portable table identifiers conservatively across schema and case rules. */
    private function correlationName(string $table): string
    {
        $segments = explode('.', $table);

        return strtolower($segments[array_key_last($segments)]);
    }

    /**
     * Correlate each visible term to exact native host ownership and vocabulary.
     *
     * @param  list<string>  $references
     * @return Builder<Term>
     */
    private function matching(Model $owner, string $vocabulary, array $references, TaxonomyBatchAuthorization $policy): Builder
    {
        $terms = $this->queries->terms($vocabulary, $policy, $owner);
        $model = $terms->getModel();
        $attachments = $this->queries->attachments();
        $pivot = $attachments->getModel();
        TaxonomyBatchOwnerAdmission::exact($attachments, $pivot->qualifyColumn('termable_type'), $owner->getMorphClass());
        TaxonomyBatchOwnerAdmission::exact($attachments, $pivot->qualifyColumn('taxonomy'), $vocabulary);
        $connection = $attachments->getModel()->getConnection();
        $grammar = $connection->getQueryGrammar();
        $left = ExactTaxonomyText::text($grammar->wrap($pivot->qualifyColumn('termable_id')), $connection->getDriverName());
        $right = ExactTaxonomyText::text($grammar->wrap($owner->getQualifiedKeyName()), $connection->getDriverName());
        $attachments->whereRaw(new ExactTaxonomyText($left, $right, $connection->getDriverName()));
        $attachments->whereColumn($pivot->qualifyColumn('term_id'), $model->getQualifiedKeyName());
        $pivotVocabulary = ExactTaxonomyText::text($grammar->wrap($pivot->qualifyColumn('taxonomy')), $connection->getDriverName());
        $termVocabulary = ExactTaxonomyText::text($grammar->wrap($model->qualifyColumn('taxonomy')), $connection->getDriverName());
        $attachments->whereRaw(new ExactTaxonomyText($pivotVocabulary, $termVocabulary, $connection->getDriverName()));
        $terms->whereExists($attachments->selectRaw('1')->toBase());
        $terms->where(function (Builder $matches) use ($references, $model): void {
            foreach ($references as $reference) {
                $matches->orWhere(function (Builder $match) use ($reference, $model): void {
                    if (Str::isUuid($reference)) {
                        $match->where($model->getQualifiedKeyName(), $reference);
                    } else {
                        TaxonomyBatchOwnerAdmission::exact($match, $model->qualifyColumn('slug'), $reference);
                    }
                });
            }
        });

        return $terms;
    }
}
