<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;

/** Preserves explicitly registered vocabulary and owner capability admission. */
final readonly class RegisteredTaxonomyBatchAuthorization implements TaxonomyBatchAuthorization
{
    /** Retain canonical owner and vocabulary admission. */
    public function __construct(private TaxonomyBatchOwnerAdmission $owners) {}

    /**
     * Admit each canonical owner capability using loaded registration facts.
     *
     * @param  list<string>  $vocabularies
     */
    public function authorizeOwners(OwnerBatch $owners, array $vocabularies): void
    {
        foreach ($owners->owners() as $owner) {
            foreach ($vocabularies as $vocabulary) {
                $this->owners->assertVocabulary($owner, $vocabulary);
            }
        }
    }

    /**
     * Preserve registered public attachment visibility.
     *
     * @param  Builder<Termable>  $query
     * @param  list<string>  $vocabularies
     */
    public function scopeAttachments(Builder $query, OwnerBatch $owners, array $vocabularies): void {}

    /**
     * Preserve the registered model's own term visibility predicates.
     *
     * @param  Builder<Term>  $query
     * @param  list<string>  $vocabularies
     */
    public function scopeTerms(Builder $query, array $vocabularies): void {}

    /**
     * Admit the canonical host capability without querying owner records.
     *
     * @param  Builder<Term>  $query
     */
    public function scopeHostTerms(Builder $query, Model $ownerPrototype, string $vocabulary): void
    {
        $this->owners->assertVocabulary($ownerPrototype, $vocabulary);
    }
}
