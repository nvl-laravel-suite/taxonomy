<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Data;

use Nvl\Taxonomy\Support\TaxonomyConfiguration;
use Nvl\Translatable\Rules\SupportedLocaleMapRule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Defines structural term data and locale-keyed display copy for term mutations.
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class MutateTermPayload extends Data
{
    /**
     * Create a term mutation payload.
     *
     * @param  array<string, array{name: string, description?: string|null}>  $translations
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $taxonomy,
        #[LiteralTypeScriptType('string')]
        public readonly string $slug,
        #[LiteralTypeScriptType('Record<string, { name: string; description?: string | null }>')]
        public readonly array $translations,
        #[LiteralTypeScriptType('string | null')]
        #[TypeScriptOptional]
        public readonly ?string $parentId = null,
        #[LiteralTypeScriptType('number')]
        #[TypeScriptOptional]
        public readonly int $position = 0,
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        #[TypeScriptOptional]
        public readonly ?array $meta = null,
        #[LiteralTypeScriptType('number | null')]
        #[TypeScriptOptional]
        public readonly ?int $expectedRevision = null,
    ) {}

    /**
     * Return validation rules for a term mutation.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'taxonomy' => ['required', 'string', 'max:64'],
            'slug' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'parentId' => ['sometimes', 'nullable', 'uuid'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'meta' => ['nullable', 'array'],
            'translations' => ['required', 'array', 'min:1', new SupportedLocaleMapRule],
            'translations.*' => ['array'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => [
                'nullable',
                'string',
                'max:'.TaxonomyConfiguration::positiveLimit('description_chars', 10000),
            ],
            'expectedRevision' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
