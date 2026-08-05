<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Taxonomy\Exceptions\DuplicateSiblingSlugException;
use Nvl\Taxonomy\Exceptions\StaleTermVersionException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Support\SlugGenerator;
use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Taxonomy\Support\TaxonomyRegistry;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Services\TranslationWriter;

/**
 * Persists validated term structure and dedicated translation rows.
 */
final readonly class TermWriter
{
    /**
     * Create the validated structural and translation writer.
     */
    public function __construct(
        private TranslationWriter $translations,
        private TaxonomyRegistry $taxonomies,
        private TermHierarchy $hierarchy,
        private SlugGenerator $slugs,
    ) {}

    /**
     * Persist one validated term and its complete translation set.
     */
    public function create(MutateTermPayload $data): Term
    {
        $definition = $this->taxonomies->get($data->taxonomy);

        $this->validate($data);
        $term = new ($definition->model)($this->baseAttributes($data));
        $term->save();
        $this->translations->replace($term, $data->translations);

        return $term->refresh()->load('translations');
    }

    /**
     * Persist one revision-checked term and translation mutation.
     */
    public function update(
        Term $term,
        MutateTermPayload $data,
        TranslationSyncMode $mode = TranslationSyncMode::Patch,
    ): Term {
        if ($data->expectedRevision === null || $term->revision !== $data->expectedRevision) {
            throw StaleTermVersionException::forTerm($term->id);
        }

        if ($term->taxonomy !== $data->taxonomy) {
            throw new \InvalidArgumentException('A term taxonomy is immutable.');
        }

        if ($term->parent_id !== $data->parentId) {
            throw new \InvalidArgumentException(
                'Use MoveTermAction to change a taxonomy term parent.',
            );
        }

        $this->validate($data, $term->id);
        $term->fill($this->baseAttributes($data));
        $term->save();
        $this->translations->sync($term, $data->translations, $mode);

        return $term->refresh()->load('translations');
    }

    private function validate(MutateTermPayload $data, ?string $termId = null): void
    {
        $definition = $this->taxonomies->get($data->taxonomy);
        $this->hierarchy->validate($data->taxonomy, $data->parentId, $termId);
        $this->slugs->assertCanonical($data->slug);

        if (strlen($data->slug) > 191) {
            throw new \InvalidArgumentException(
                'Taxonomy slugs may not exceed 191 characters.',
            );
        }

        if ($data->position < 0) {
            throw new \InvalidArgumentException(
                'Taxonomy term positions cannot be negative.',
            );
        }

        if ($data->translations === []) {
            throw new \InvalidArgumentException(
                'Taxonomy terms require at least one translation.',
            );
        }

        $this->validateTranslations($data->translations);

        $json = json_encode($data->meta, JSON_THROW_ON_ERROR);

        if (strlen($json) > TaxonomyConfiguration::positiveLimit('metadata_bytes', 65536)) {
            throw new \InvalidArgumentException('Taxonomy metadata exceeds the configured size limit.');
        }

        $this->validateMetadataDepth(
            $data->meta,
            TaxonomyConfiguration::positiveLimit('metadata_depth', 8),
        );

        if ($definition->metadataRules !== []) {
            $allowedKeys = array_map(
                static fn (string $field): string => explode('.', $field, 2)[0],
                array_keys($definition->metadataRules),
            );
            $unknownKeys = in_array('*', $allowedKeys, true)
                ? []
                : array_diff(array_keys($data->meta ?? []), $allowedKeys);

            if ($unknownKeys !== []) {
                throw new \InvalidArgumentException(
                    'Unknown taxonomy metadata keys: '.implode(', ', $unknownKeys).'.',
                );
            }

            $validator = Validator::make($data->meta ?? [], $definition->metadataRules);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
        }

        $duplicate = Term::query()
            ->where('taxonomy', $data->taxonomy)
            ->where('parent_key', $data->parentId ?? '__root__')
            ->where('slug', $data->slug)
            ->when($termId !== null, static fn ($query) => $query->whereKeyNot($termId))
            ->exists();

        if ($duplicate) {
            throw DuplicateSiblingSlugException::forSlug($data->taxonomy, $data->slug);
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
    private function validateTranslations(array $translations): void
    {
        foreach ($translations as $locale => $translation) {
            if (! is_string($locale) || $locale === '' || ! is_array($translation)) {
                throw new \InvalidArgumentException(
                    'Taxonomy translations require string locale keys and object payloads.',
                );
            }

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

    /**
     * @return array<string, mixed>
     */
    private function baseAttributes(MutateTermPayload $data): array
    {
        return [
            'taxonomy' => $data->taxonomy,
            'parent_id' => $data->parentId,
            'slug' => $data->slug,
            'position' => $data->position,
            'meta' => $data->meta,
        ];
    }
}
