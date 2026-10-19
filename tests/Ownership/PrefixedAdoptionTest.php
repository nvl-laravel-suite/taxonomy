<?php

declare(strict_types=1);
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipActor;
use Nvl\Taxonomy\Tests\Fixtures\OwnershipCompany;
use Nvl\Taxonomy\Tests\Fixtures\TaxonomyPrefixedOwnershipTestCase;

uses(TaxonomyPrefixedOwnershipTestCase::class);
it('prepares backfills and activates prefixed taxonomy storage without duplicating constraints', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        expect(OwnershipCompany::findOrFail(7)->getConnection()->getTablePrefix())->toBe('review_');
    });
});
