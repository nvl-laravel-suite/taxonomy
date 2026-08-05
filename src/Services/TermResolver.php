<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Taxonomy\Enums\TermChangeOperation;
use Nvl\Taxonomy\Events\TermChanged;
use Nvl\Taxonomy\Exceptions\AmbiguousTermReferenceException;
use Nvl\Taxonomy\Exceptions\ClosedVocabularyException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Support\SlugGenerator;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Translatable\Services\ContentLocale;

/**
 * Resolves term references in batches and creates missing open-vocabulary roots.
 */
final readonly class TermResolver
{
    /**
     * Create the batched term reference resolver.
     */
    public function __construct(
        private TaxonomyRegistry $taxonomies,
        private SlugGenerator $slugs,
        private ContentLocale $contentLocale,
        private TermWriter $writer,
    ) {}

    /**
     * Resolve ordered references and create missing roots when permitted.
     *
     * @param  list<Term|string>  $references
     * @return list<Term>
     */
    public function resolve(string $taxonomy, array $references, bool $createMissing = true): array
    {
        $definition = $this->taxonomies->get($taxonomy);
        $modelReferenceIds = [];
        $stringReferences = [];

        foreach ($references as $reference) {
            if ($reference instanceof Term) {
                if (! $reference->exists || $reference->taxonomy !== $taxonomy) {
                    throw new InvalidArgumentException(
                        "Term [{$reference->id}] does not belong to taxonomy [{$taxonomy}].",
                    );
                }

                $modelReferenceIds[$reference->id] = true;

                continue;
            }

            $input = trim($reference);

            if ($input === '') {
                throw new InvalidArgumentException('Taxonomy term inputs cannot be empty.');
            }

            $stringReferences[] = $input;
        }

        $identifiers = array_values(array_unique([
            ...array_keys($modelReferenceIds),
            ...array_filter($stringReferences, Str::isUuid(...)),
        ]));
        $slugs = [];

        foreach ($stringReferences as $input) {
            if (! Str::isUuid($input)) {
                $slugs[$input] = $this->slugs->generate($input);
            }
        }

        if ($identifiers === [] && $slugs === []) {
            return [];
        }

        $modelClass = $definition->model;
        $candidates = $modelClass::query()
            ->where('taxonomy', $taxonomy)
            ->where(static function ($query) use ($identifiers, $slugs): void {
                if ($identifiers !== []) {
                    $query->whereIn('id', $identifiers);
                }

                if ($slugs !== []) {
                    $method = $identifiers !== [] ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('slug', array_values(array_unique($slugs)));
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $byId = $candidates->keyBy('id');
        $bySlug = $candidates->groupBy('slug');
        $resolved = [];
        $missing = [];

        foreach (array_keys($modelReferenceIds) as $modelReferenceId) {
            $candidate = $byId->get($modelReferenceId);

            if (! $candidate instanceof Term) {
                throw new InvalidArgumentException(
                    "Term [{$modelReferenceId}] no longer exists in taxonomy [{$taxonomy}].",
                );
            }

            $resolved[$candidate->id] = $candidate;
        }

        foreach ($stringReferences as $input) {
            if (Str::isUuid($input)) {
                $candidate = $byId->get($input);

                if ($candidate instanceof Term) {
                    $resolved[$candidate->id] = $candidate;
                } elseif ($createMissing) {
                    throw new InvalidArgumentException(
                        "Unknown taxonomy term identifier [{$input}].",
                    );
                }

                continue;
            }

            $slug = $slugs[$input];
            $matches = $bySlug->get($slug);
            $matchCount = $matches?->count() ?? 0;

            if ($matchCount > 1) {
                throw AmbiguousTermReferenceException::forSlug($taxonomy, $slug);
            }

            $candidate = $matches?->first();

            if ($candidate instanceof Term) {
                $resolved[$candidate->id] = $candidate;
            } else {
                $missing[$slug] = $input;
            }
        }

        if ($missing === [] || ! $createMissing) {
            return $this->orderedResults($references, $resolved);
        }

        if (! $definition->open) {
            throw new ClosedVocabularyException($taxonomy, array_values($missing));
        }

        $database = DB::connection((new $modelClass)->getConnectionName());

        foreach ($missing as $slug => $name) {
            $wasCreated = true;

            try {
                $created = $database->transaction(
                    fn (): Term => $this->writer->create(MutateTermPayload::from([
                        'taxonomy' => $taxonomy,
                        'slug' => $slug,
                        'translations' => [
                            $this->contentLocale->get() => ['name' => $name],
                        ],
                    ])),
                );
            } catch (UniqueConstraintViolationException $exception) {
                $wasCreated = false;
                $created = $modelClass::query()
                    ->where('taxonomy', $taxonomy)
                    ->where('parent_key', '__root__')
                    ->where('slug', $slug)
                    ->lockForUpdate()
                    ->first();

                if (! $created instanceof Term) {
                    throw $exception;
                }
            }

            $resolved[$created->id] = $created;

            if ($wasCreated) {
                TermChanged::dispatch(
                    $created->id,
                    $created->taxonomy,
                    TermChangeOperation::Created,
                    $created->revision,
                );
            }
        }

        return $this->orderedResults($references, $resolved);
    }

    /**
     * @param  list<Term|string>  $references
     * @param  array<string, Term>  $resolved
     * @return list<Term>
     */
    private function orderedResults(array $references, array $resolved): array
    {
        $bySlug = [];

        foreach ($resolved as $term) {
            $bySlug[$term->slug] ??= $term;
        }

        $ordered = [];

        foreach ($references as $reference) {
            if ($reference instanceof Term) {
                $candidate = $resolved[$reference->id] ?? null;
            } else {
                $input = trim($reference);
                $candidate = Str::isUuid($input)
                    ? ($resolved[$input] ?? null)
                    : ($bySlug[$this->slugs->generate($input)] ?? null);
            }

            if ($candidate instanceof Term) {
                $ordered[$candidate->id] = $candidate;
            }
        }

        return array_values($ordered);
    }
}
