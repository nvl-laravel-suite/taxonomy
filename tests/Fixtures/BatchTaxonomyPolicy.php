<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Relations\ExactTaxonomyText;
use Nvl\Taxonomy\Services\TaxonomyBatchOwnerAdmission;

/** SQL-only private vocabulary policy deliberately exercising nested OR clauses. */
final readonly class BatchTaxonomyPolicy implements TaxonomyBatchAuthorization
{
    /** Select whether this private adapter filters attachment positions. */
    public function __construct(private bool $filterAttachments = true) {}

    /**
     * Admit canonically loaded owners without performing SQL.
     *
     * @param  list<string>  $vocabularies
     */
    public function authorizeOwners(OwnerBatch $owners, array $vocabularies): void {}

    /**
     * Restrict attachments to the visible pivot position, with a harmless OR.
     *
     * @param  Builder<Termable>  $query
     * @param  list<string>  $vocabularies
     */
    public function scopeAttachments(Builder $query, OwnerBatch $owners, array $vocabularies): void
    {
        if ($this->filterAttachments) {
            $query->where($query->getModel()->qualifyColumn('position'), 999)->orWhere($query->getModel()->qualifyColumn('position'), 0);
        }
    }

    /**
     * Restrict terms to the visible slug, with a harmless OR.
     *
     * @param  Builder<Term>  $query
     * @param  list<string>  $vocabularies
     */
    public function scopeTerms(Builder $query, array $vocabularies): void
    {
        $query->where($query->getModel()->qualifyColumn('slug'), 'absent')->orWhere($query->getModel()->qualifyColumn('slug'), 'visible');
    }

    /**
     * Apply the same term visibility to a correlated host query.
     *
     * @param  Builder<Term>  $query
     */
    public function scopeHostTerms(Builder $query, Model $ownerPrototype, string $vocabulary): void
    {
        $query->where(function (Builder $visible): void {
            $visible->where($visible->getModel()->qualifyColumn('slug'), 'absent')->orWhere($visible->getModel()->qualifyColumn('slug'), 'visible');
        });
        if ($this->filterAttachments) {
            $pivot = new Termable;
            $attachments = $pivot->newQuery()->where($pivot->qualifyColumn('position'), 0)
                ->whereColumn($pivot->qualifyColumn('term_id'), $query->getModel()->getQualifiedKeyName());
            TaxonomyBatchOwnerAdmission::exact($attachments, $pivot->qualifyColumn('termable_type'), $ownerPrototype->getMorphClass());
            $connection = $pivot->getConnection();
            $grammar = $connection->getQueryGrammar();
            $left = ExactTaxonomyText::text($grammar->wrap($pivot->qualifyColumn('termable_id')), $connection->getDriverName());
            $right = ExactTaxonomyText::text($grammar->wrap($ownerPrototype->getQualifiedKeyName()), $connection->getDriverName());
            $attachments->whereRaw(new ExactTaxonomyText($left, $right, $connection->getDriverName()));
            $query->whereExists($attachments->selectRaw('1')->toBase());
        }
    }
}
