<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Tests\Fixtures;

use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Taxonomy\Actions\CreateTermAction;
use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Taxonomy\Models\Term;

/** Constructs actual package workflow records under the admitted native owner. */
final class OwnershipTaxonomyScenario
{
    public static function term(string $slug = 'visible', ?string $parentId = null): Term
    {
        return app(CreateTermAction::class)->execute(MutateTermPayload::from(['taxonomy' => 'category', 'slug' => $slug, 'parentId' => $parentId, 'translations' => ['en' => ['name' => $slug]]]));
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
