<?php

declare(strict_types=1);
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Models\TermTranslation;
use Nvl\Taxonomy\Services\TaxonomyTerms;
use Nvl\Taxonomy\Services\TermModelRegistry;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipActor;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipCompany;
use Nvl\Taxonomy\Tests\OwnershipSourceTestCase;

uses(OwnershipSourceTestCase::class);
it('rolls back structural and localized rows together on the actual package source', function (): void {
    TermTranslation::creating(static function (TermTranslation $locale): void {
        if ($locale->locale === 'bg') {
            throw new RuntimeException('Translation persistence failed.');
        }
    });
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        expect(fn () => app(TaxonomyTerms::class)->create(['taxonomy' => 'category', 'slug' => 'rollback', 'translations' => ['en' => ['name' => 'First'], 'bg' => ['name' => 'Second']]], SystemActor::Background))->toThrow(RuntimeException::class, 'Translation persistence failed.');
        expect(TermModelRegistry::query()->count())->toBe(0)->and(TermTranslation::query()->count())->toBe(0);
    });
});
