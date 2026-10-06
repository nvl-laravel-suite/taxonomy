<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;

/**
 * Query-free private authorization expressed inside mandatory Taxonomy predicates.
 *
 * @api
 */
interface TaxonomyBatchAuthorization
{
    /**
     * Admit the complete canonically reloaded owner set without SQL.
     *
     * @param  list<string>  $vocabularies
     */
    public function authorizeOwners(OwnerBatch $owners, array $vocabularies): void;

    /**
     * Restrict attachment rows before window ranking without executing SQL.
     *
     * @param  Builder<Termable>  $query
     * @param  list<string>  $vocabularies
     */
    public function scopeAttachments(Builder $query, OwnerBatch $owners, array $vocabularies): void;

    /**
     * Restrict registered structural term rows without executing SQL.
     *
     * @param  Builder<Term>  $query
     * @param  list<string>  $vocabularies
     */
    public function scopeTerms(Builder $query, array $vocabularies): void;

    /**
     * Express complete host-owner and attached-term visibility in correlated SQL.
     *
     * @param  Builder<Term>  $query
     */
    public function scopeHostTerms(Builder $query, Model $ownerPrototype, string $vocabulary): void;
}
