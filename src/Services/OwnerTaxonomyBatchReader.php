<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Owners\OwnerResultMap;
use Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization;
use Nvl\Taxonomy\Data\OwnerTaxonomyTerm;
use Nvl\Taxonomy\Data\OwnerTaxonomyTermList;
use Nvl\Taxonomy\Data\OwnerTaxonomyTerms;
use Nvl\Taxonomy\Exceptions\TaxonomyBatchReadException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Services\RelatedTranslationStore;
use Nvl\Translatable\Services\TranslationResolver;
use stdClass;

/** Reads bounded authorized attachment facts and projects pure preloaded DTOs. */
final readonly class OwnerTaxonomyBatchReader
{
    /** Retain canonical admission, SQL policy and translation projection dependencies. */
    public function __construct(
        private TaxonomyBatchOwnerAdmission $owners,
        private TaxonomyRegistry $vocabularies,
        private TaxonomyBatchQueryBuilder $queries,
        private TaxonomyBatchAuthorization $policy,
        private ContentLocale $locale,
        private RelatedTranslationStore $translations,
        private TranslationResolver $resolver,
    ) {}

    /**
     * Read finite requested vocabularies without per-owner or per-term SQL.
     *
     * @param  list<Model>  $owners
     * @param  array<array-key, mixed>  $vocabularies
     */
    public function read(array $owners, array $vocabularies, ?string $locale = null): OwnerTaxonomyTerms
    {
        $input = OwnerBatch::fromModels($owners);
        if (! array_is_list($vocabularies) || count($vocabularies) > 20) {
            throw new InvalidArgumentException('Taxonomy batches accept at most twenty declared vocabulary names.');
        }
        $requested = [];
        $locales = [];
        $locale ??= $this->locale->get();
        foreach ($vocabularies as $vocabulary) {
            if (! is_string($vocabulary)) {
                throw new InvalidArgumentException('Every requested taxonomy vocabulary must be a string.');
            }
            $definition = $this->vocabularies->get($vocabulary);
            $prototype = new $definition->model;
            if ($prototype->getConnection() !== (new Term)->getConnection()) {
                throw new TaxonomyBatchReadException('Registered taxonomy terms require the canonical package connection.');
            }
            $translationDefinition = $prototype->translationDefinition();
            $locales[$vocabulary] = $translationDefinition->localeChain($locale);
            $requested[$vocabulary] = true;
        }
        $vocabularies = array_keys($requested);
        /** @var array<string, array<array-key, OwnerTaxonomyTermList>> $values */
        $values = [];
        if ($input->isEmpty()) {
            return OwnerTaxonomyTerms::fromMap(new OwnerResultMap($input, $values));
        }
        $admitted = $this->owners->admit($input, $vocabularies);
        $this->policy->authorizeOwners($admitted, $vocabularies);
        foreach ($admitted->identities() as $identity) {
            $empty = new stdClass;
            foreach ($vocabularies as $vocabulary) {
                $empty->{$vocabulary} = [];
            }
            $values[$identity->type][$identity->id] = new OwnerTaxonomyTermList($empty);
        }
        if ($vocabularies === []) {
            return OwnerTaxonomyTerms::fromMap(new OwnerResultMap($admitted, $values));
        }
        /** @var Collection<int, stdClass&object{id: string, term_id: string, termable_type: string, termable_id: string, taxonomy: string, position: int, nvl_taxonomy_rank: int}> $facts */
        $facts = $this->queries->probe($this->queries->batch($admitted, $vocabularies, $this->policy))->get();
        $ids = [];
        foreach ($facts as $fact) {
            if ($fact->nvl_taxonomy_rank > 100) {
                throw new TaxonomyBatchReadException('A requested owner vocabulary exceeds one hundred visible terms.');
            }
            $ids[$fact->taxonomy][$fact->term_id] = true;
        }
        $terms = [];
        $displayRows = [];
        foreach ($vocabularies as $vocabulary) {
            $vocabularyIds = array_keys($ids[$vocabulary] ?? []);
            if ($vocabularyIds === []) {
                continue;
            }
            $query = $this->queries->terms($vocabulary, $this->policy);
            $prototype = $query->getModel();
            $loaded = $query->whereIn($prototype->getQualifiedKeyName(), $vocabularyIds)
                ->get(array_map($prototype->qualifyColumn(...), ['id', 'taxonomy', 'slug', 'parent_id', 'position', 'revision']));
            foreach ($loaded as $term) {
                $terms[$vocabulary][$term->id] = $term;
            }
            $definition = $prototype->translationDefinition();
            $translationModel = new $definition->translationModel;
            $translationQuery = $translationModel->newQuery()->withoutEagerLoads();
            $this->translations->scopeQuery($translationQuery, $prototype, $definition);
            $foreignKey = $translationModel->qualifyColumn($definition->foreignKey($prototype->getTable()));
            $translationQuery->whereIn($foreignKey, array_keys($terms[$vocabulary] ?? []));
            $translationQuery->whereIn($translationModel->qualifyColumn($definition->localeKey), $locales[$vocabulary]);
            foreach ($translationQuery->get(array_map($translationModel->qualifyColumn(...), ['id', $definition->foreignKey($prototype->getTable()), $definition->localeKey, 'name', 'description'])) as $row) {
                $ownerId = $row->getAttribute($definition->foreignKey($prototype->getTable()));
                if (! is_string($ownerId)) {
                    throw new TaxonomyBatchReadException('A taxonomy translation has invalid structural ownership.');
                }
                $displayRows[$vocabulary][$ownerId][] = $row;
            }
        }
        foreach ($facts as $fact) {
            $term = $terms[$fact->taxonomy][$fact->term_id] ?? null;
            if (! $term instanceof Term) {
                continue;
            }
            $definition = $term->translationDefinition();
            /** @var Collection<int, Model> $rows */
            $rows = new Collection($displayRows[$fact->taxonomy][$fact->term_id] ?? []);
            $name = $this->resolver->resolve($rows, $definition, 'name', $definition->assertLocale($locale), $locales[$fact->taxonomy])->value;
            $description = $this->resolver->resolve($rows, $definition, 'description', $definition->assertLocale($locale), $locales[$fact->taxonomy])->value;
            $list = $values[$fact->termable_type][$fact->termable_id]->vocabularies->{$fact->taxonomy};
            if (! is_array($list) || ! array_is_list($list)) {
                throw new TaxonomyBatchReadException('A requested taxonomy vocabulary requires an ordered DTO list.');
            }
            $list[] = new OwnerTaxonomyTerm(
                $term->id,
                $fact->taxonomy,
                $term->slug,
                $term->parent_id,
                $fact->position,
                is_string($name) ? $name : '',
                is_string($description) ? $description : null,
            );
            $values[$fact->termable_type][$fact->termable_id]->vocabularies->{$fact->taxonomy} = $list;
        }

        return OwnerTaxonomyTerms::fromMap(new OwnerResultMap($admitted, $values));
    }
}
