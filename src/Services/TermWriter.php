<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Taxonomy\Exceptions\DuplicateSiblingSlugException;
use Nvl\Taxonomy\Exceptions\StaleTermVersionException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Ownership\TaxonomyBoundary as OwnershipBoundary;
use Nvl\Taxonomy\Ownership\TaxonomyOwnership;
use Nvl\Taxonomy\Support\SlugGenerator;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Services\RelatedTranslationStore;
use Nvl\Translatable\Services\TranslationPayloadValidator;

/**
 * Persists validated term structure and dedicated translation rows.
 */
final readonly class TermWriter
{
    /**
     * Create the validated structural and translation writer.
     */
    public function __construct(
        private RelatedTranslationStore $translations,
        private TranslationPayloadValidator $translationPayloads,
        private LocaleCatalog $locales,
        private TaxonomyRegistry $taxonomies,
        private TermHierarchy $hierarchy,
        private SlugGenerator $slugs,
        private OwnershipBoundary $boundary,
        private OwnershipWriter $ownershipWriter,
    ) {}

    /**
     * Persist one validated term and its complete translation set.
     */
    /** @param array{taxonomy:string, slug:string, translations:array<string, array{name:string, description?:?string}>, parent_id?:?string, position?:int, meta?:?array<string, mixed>} $data */
    public function create(array $data): Term
    {
        $data = $this->normalize($data, false);
        $definition = $this->taxonomies->get($data['taxonomy']);

        $this->validate($data);
        if (TaxonomyOwnership::declared()) {
            $term = $this->ownershipWriter->create($definition->model, function (array $ownership) use ($definition, $data): Term {
                $term = new ($definition->model)($this->baseAttributes($data));
                $term->forceFill($ownership)->save();

                return $term;
            });
        } else {
            $term = new ($definition->model)($this->baseAttributes($data));
            $term->forceFill($this->boundary->attributes('taxonomy.terms'))->save();
        }
        $this->writeTranslations($term, $data['translations'], TranslationSyncMode::Replace);

        return $term->refresh()->load('translations');
    }

    /**
     * Persist one revision-checked term and translation mutation.
     */
    public function update(
        Term $term,
        array $data,
        TranslationSyncMode $mode = TranslationSyncMode::Patch,
    ): Term {
        $data = $this->normalize($data, true, $term);
        $this->boundary->assertRecord($term, 'taxonomy.terms');
        if ($term->revision !== $data['expected_revision']) {
            throw StaleTermVersionException::forTerm($term->id);
        }

        if ($term->taxonomy !== $data['taxonomy']) {
            throw new \InvalidArgumentException('A term taxonomy is immutable.');
        }

        if ($term->parent_id !== $data['parent_id']) {
            throw new \InvalidArgumentException(
                'Use TaxonomyTerms::move to change a taxonomy term parent.',
            );
        }

        $this->validate($data, $term->id);
        $term->fill($this->baseAttributes($data));
        $term->save();
        $this->writeTranslations($term, $data['translations'], $mode);

        return $term->refresh()->load('translations');
    }

    /** @param array<string, mixed> $data */
    private function validate(array $data, ?string $termId = null): void
    {
        $definition = $this->taxonomies->get($data['taxonomy']);
        $this->hierarchy->validate($data['taxonomy'], $data['parent_id'], $termId);
        $this->slugs->assertCanonical($data['slug']);

        if (strlen($data['slug']) > 191) {
            throw new \InvalidArgumentException(
                'Taxonomy slugs may not exceed 191 characters.',
            );
        }

        if ($data['position'] < 0) {
            throw new \InvalidArgumentException(
                'Taxonomy term positions cannot be negative.',
            );
        }

        if ($data['translations'] === []) {
            throw new \InvalidArgumentException(
                'Taxonomy terms require at least one translation.',
            );
        }

        $this->validateTranslations($data['translations'], $definition->model);

        $json = json_encode($data['meta'], JSON_THROW_ON_ERROR);

        if (strlen($json) > TaxonomyConfiguration::positiveLimit('metadata_bytes', 65536)) {
            throw new \InvalidArgumentException('Taxonomy metadata exceeds the configured size limit.');
        }

        $this->validateMetadataDepth(
            $data['meta'],
            TaxonomyConfiguration::positiveLimit('metadata_depth', 8),
        );

        if ($definition->metadataRules !== []) {
            $allowedKeys = array_map(
                static fn (string $field): string => explode('.', $field, 2)[0],
                array_keys($definition->metadataRules),
            );
            $unknownKeys = in_array('*', $allowedKeys, true)
                ? []
                : array_diff(array_keys($data['meta'] ?? []), $allowedKeys);

            if ($unknownKeys !== []) {
                throw new \InvalidArgumentException(
                    'Unknown taxonomy metadata keys: '.implode(', ', $unknownKeys).'.',
                );
            }

            $validator = Validator::make($data['meta'] ?? [], $definition->metadataRules);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
        }

        $duplicate = TermModelRegistry::query()
            ->where('taxonomy', $data['taxonomy'])
            ->where('parent_key', $data['parent_id'] ?? '__root__')
            ->where('slug', $data['slug'])
            ->when($termId !== null, static fn ($query) => $query->whereKeyNot($termId))
            ->exists();

        if ($duplicate) {
            throw DuplicateSiblingSlugException::forSlug($data['taxonomy'], $data['slug']);
        }
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function validateMetadataDepth(?array $metadata, int $maximumDepth): void
    {
        $measure = function (mixed $value, int $depth) use (&$measure, $maximumDepth): void {
            if (! is_array($value)) {
                return;
            }

            if ($depth > $maximumDepth) {
                throw new \InvalidArgumentException(
                    "Taxonomy metadata exceeds the configured depth of {$maximumDepth}.",
                );
            }

            foreach ($value as $nested) {
                $measure($nested, $depth + 1);
            }
        };

        $measure($metadata, 1);
    }

    /**
     * Validate runtime locale keys and translation payload shapes.
     *
     * @param  array<mixed>  $translations
     */
    private function validateTranslations(array $translations, string $modelClass): void
    {
        $definition = (new $modelClass)->translationDefinition();
        $this->translationPayloads->validate($definition, $translations);
        foreach ($translations as $locale => $translation) {
            if (! is_string($locale) || $locale === '' || ! is_array($translation)) {
                throw new \InvalidArgumentException(
                    'Taxonomy translations require string locale keys and object payloads.',
                );
            }

            $definition->assertLocale($this->locales->assertSupported($locale));
            $this->validateTranslation($translation);
        }
    }

    /**
     * Validate one runtime translation payload.
     *
     * @param  array<mixed>  $translation
     */
    private function validateTranslation(array $translation): void
    {
        if (array_diff(array_keys($translation), ['name', 'description']) !== []) {
            throw new \InvalidArgumentException('Unknown taxonomy translation fields.');
        }
        $name = $translation['name'] ?? null;
        $description = $translation['description'] ?? null;

        if (! is_string($name) || trim($name) === '' || Str::length($name) > 255) {
            throw new \InvalidArgumentException(
                'Taxonomy translation names must contain between 1 and 255 characters.',
            );
        }

        if ($description !== null
            && (! is_string($description)
                || Str::length($description) > TaxonomyConfiguration::positiveLimit(
                    'description_chars',
                    10000,
                ))) {
            throw new \InvalidArgumentException(
                'Taxonomy translation descriptions exceed the configured character limit.',
            );
        }
    }

    /** @param array<string, array<string, mixed>> $translations */
    private function writeTranslations(Term $term, array $translations, TranslationSyncMode $mode): void
    {
        $definition = $term->translationDefinition();
        $locales = [];
        foreach ($translations as $locale => $attributes) {
            $normalized = $definition->assertLocale($this->locales->assertSupported($locale));
            if (isset($locales[$normalized])) {
                throw new \InvalidArgumentException('Duplicate normalized taxonomy translation locale.');
            }
            $locales[$normalized] = true;
            $this->translations->upsert($term, $definition, $normalized, $attributes);
        }
        if ($mode === TranslationSyncMode::Replace) {
            $this->translations->deleteExcept($term, $definition, array_keys($locales));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function baseAttributes(array $data): array
    {
        return [
            'taxonomy' => $data['taxonomy'],
            'parent_id' => $data['parent_id'],
            'slug' => $data['slug'],
            'position' => $data['position'],
            'meta' => $data['meta'],
        ];
    }

    /** @param array<string, mixed> $attributes
     * @return array{taxonomy:string, slug:string, translations:array<string, array<string, mixed>>, parent_id:?string, position:int, meta:?array, expected_revision:?int}
     */
    private function normalize(array $attributes, bool $updating, ?Term $term = null): array
    {
        $allowed = ['taxonomy', 'slug', 'translations', 'parent_id', 'position', 'meta', 'expected_revision'];
        if (array_is_list($attributes) || array_diff(array_keys($attributes), $allowed) !== []) {
            throw new \InvalidArgumentException('Unknown taxonomy term attributes.');
        }
        if ($updating && (! isset($attributes['expected_revision']) || ! is_int($attributes['expected_revision']))) {
            throw StaleTermVersionException::forTerm($term?->id ?? 'unknown');
        }
        foreach ($updating ? ['translations'] : ['taxonomy', 'slug', 'translations'] as $required) {
            if (! array_key_exists($required, $attributes)) {
                throw new \InvalidArgumentException("Missing taxonomy term attribute [{$required}].");
            }
        }
        if ($updating && $term instanceof Term) {
            $attributes = [
                'taxonomy' => $term->taxonomy,
                'slug' => $term->slug,
                'parent_id' => $term->parent_id,
                'position' => $term->position,
                'meta' => $term->meta,
                ...$attributes,
            ];
        }
        if (! is_string($attributes['taxonomy']) || ! is_string($attributes['slug']) || ! is_array($attributes['translations'])) {
            throw new \InvalidArgumentException('Invalid taxonomy term attributes.');
        }
        $parent = $attributes['parent_id'] ?? null;
        $position = $attributes['position'] ?? 0;
        $meta = $attributes['meta'] ?? null;
        $revision = $attributes['expected_revision'] ?? null;
        if (($parent !== null && ! is_string($parent)) || ! is_int($position) || ($meta !== null && ! is_array($meta)) || ($revision !== null && ! is_int($revision))) {
            throw new \InvalidArgumentException('Invalid taxonomy term structure or expected revision.');
        }
        if (! $updating && array_key_exists('expected_revision', $attributes)) {
            throw new \InvalidArgumentException('Term creation cannot supply an expected revision.');
        }

        return [
            'taxonomy' => $attributes['taxonomy'], 'slug' => $attributes['slug'],
            'translations' => $attributes['translations'], 'parent_id' => $parent,
            'position' => $position, 'meta' => $meta, 'expected_revision' => $revision,
        ];
    }
}
