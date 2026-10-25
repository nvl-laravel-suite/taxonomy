<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Services\TaxonomyTerms;

/** Constructs actual package workflow records under the admitted native owner. */
final class OwnershipTaxonomyScenario
{
    public static function term(string $slug = 'visible', ?string $parentId = null): Term
    {
        return app(TaxonomyTerms::class)->create(['taxonomy' => 'category', 'slug' => $slug, 'parent_id' => $parentId, 'translations' => ['en' => ['name' => $slug]]], SystemActor::Background);
    }

    public static function host(): OwnershipTaxonomyOwner
    {
        return app(OwnershipWriter::class)->create(OwnershipTaxonomyOwner::class, static function (OwnershipAssignment $assignment): OwnershipTaxonomyOwner {
            $host = new OwnershipTaxonomyOwner;
            $host->forceFill([...$assignment->attributes(), 'name' => 'Subject'])->save();

            return $host;
        });
    }
}
