<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Translatable\Services\ContentLocale;

/**
 * Generates and validates stable locale-aware canonical taxonomy slugs.
 */
class SlugGenerator
{
    /**
     * Create the locale-aware slug generator.
     */
    public function __construct(
        private readonly ContentLocale $contentLocale,
    ) {}

    /**
     * Generate a stable canonical slug from display input.
     */
    public function generate(string $source): string
    {
        $configuredLocale = config('taxonomy.slugs.locale');
        $locale = is_string($configuredLocale) && $configuredLocale !== ''
            ? $configuredLocale
            : $this->contentLocale->get();

        $slug = Str::slug($source, '-', $locale);

        if (empty($slug)) {
            $slug = Str::slug(Str::ascii($source, $locale), '-');

            if (empty($slug)) {
                $slug = 'term-'.substr(md5($source), 0, 8);
            }
        }

        return $slug;
    }

    /**
     * Require a lowercase ASCII slug in canonical kebab-case.
     */
    public function assertCanonical(string $slug): void
    {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1
            || Str::isUuid($slug)) {
            throw new InvalidArgumentException(
                "Taxonomy slug [{$slug}] must use canonical lowercase kebab-case and cannot be a UUID.",
            );
        }
    }
}
