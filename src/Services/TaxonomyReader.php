<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Contracts\TaxonomyCatalogAuthorization;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyRegistry;

/**
 * Scoped native term and owner batch reads.
 *
 * @api
 */
final readonly class TaxonomyReader
{
    public function __construct(
        private TermResolver $resolver,
        private OwnerTaxonomyBatchReader $owners,
        private TaxonomyTree $trees,
        private TaxonomyRegistry $taxonomies,
        private TaxonomyCatalogAuthorization $catalogAuthorization,
        private TaxonomyActorPolicy $actors,
    ) {}

    /** @param list<Term|string> $references
     * @return list<Term>
     */
    public function resolve(string $taxonomy, array $references, Authenticatable|SystemActor $actor): array
    {
        $actor = $this->actors->authorize($actor, 'resolve');
        $this->catalogAuthorization->authorizeVocabulary($actor, $taxonomy);
        $definition = $this->taxonomies->get($taxonomy);
        $connection = (new ($definition->model))->getConnectionName();

        return DB::connection($connection)->transaction(
            fn (): array => $this->resolver->resolve($taxonomy, $references, $actor),
            TaxonomyConfiguration::transactionAttempts(),
        );
    }

    /** @param list<Model> $owners
     * @param  list<string>  $vocabularies
     * @return array{owners: \stdClass, order: list<array{type: string, id: string}>}
     */
    public function forOwners(array $owners, array $vocabularies, Authenticatable|SystemActor $actor, ?string $locale = null): array
    {
        $actor = $this->actors->authorize($actor, 'read');

        return $this->owners->read($owners, $vocabularies, $actor, $locale);
    }

    /** @return Collection<int, Term> */
    public function tree(string $taxonomy, Authenticatable|SystemActor $actor, ?string $locale = null, int $limit = 500): Collection
    {
        $actor = $this->actors->authorize($actor, 'read');
        $this->catalogAuthorization->authorizeVocabulary($actor, $taxonomy);

        return $this->trees->boundedFor($taxonomy, $locale, $limit);
    }

    public function term(string $taxonomy, string $id, Authenticatable|SystemActor $actor, ?string $locale = null): ?Term
    {
        $actor = $this->actors->authorize($actor, 'read');
        $this->catalogAuthorization->authorizeVocabulary($actor, $taxonomy);
        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException('Taxonomy term identities must be UUIDs.');
        }
        $definition = $this->taxonomies->get($taxonomy);

        return $definition->model::query()->withoutEagerLoads()
            ->where('taxonomy', $taxonomy)->whereKey($id)
            ->withResolvedTranslations($locale)->first();
    }
}
