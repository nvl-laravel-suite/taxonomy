<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Nvl\Taxonomy\Actions\AttachTermsAction;
use Nvl\Taxonomy\Actions\MergeTermsAction;
use Nvl\Taxonomy\Actions\MoveTermAction;
use Nvl\Taxonomy\Actions\SyncTermAttachmentsAction;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Taxonomy\Tests\Fixtures\TaxonomyTenancyScenario;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

it('allows the same slug in two tenants but never attaches a foreign term', function (): void {
    $scenario = TaxonomyTenancyScenario::install();
    $a = $scenario->term($scenario::A);
    $b = $scenario->term($scenario::B);
    $owner = $scenario->owner($scenario::A);

    expect($a->id)->not->toBe($b->id)
        ->and(fn () => $scenario->run($scenario::A, fn () => app(AttachTermsAction::class)
            ->execute($owner, 'tag', [$b])))->toThrow(TenantBoundaryViolation::class);
});

it('rejects forged parents and cross-tenant move and merge targets', function (): void {
    $scenario = TaxonomyTenancyScenario::install();
    $termA = $scenario->term($scenario::A, 'a', 'category');
    $termB = $scenario->term($scenario::B, 'b', 'category');
    $forged = clone $termA;
    $forged->parent_id = $termB->id;

    expect(fn () => $scenario->run($scenario::A, fn () => app(MoveTermAction::class)
        ->execute($forged, $termB->id, 0, $termA->revision)))->toThrow(Throwable::class)
        ->and(fn () => $scenario->run($scenario::A, fn () => app(MergeTermsAction::class)
            ->execute($termA, $termB, $termA->revision, $termB->revision)))->toThrow(Throwable::class);
});

it('keeps exclusive attachments local and rejects retained loaded foreign rows', function (): void {
    $scenario = TaxonomyTenancyScenario::install();
    $categoryA = $scenario->term($scenario::A, 'category-a', 'category');
    $categoryB = $scenario->term($scenario::B, 'category-b', 'category');
    /** @var Post $owner */
    $owner = $scenario->owner($scenario::A);
    $scenario->run($scenario::A, fn () => app(SyncTermAttachmentsAction::class)
        ->execute($owner, 'category', [$categoryA]));

    $owner->setRelation('categories', new Collection([$categoryB]));

    expect(fn () => $scenario->run($scenario::A, fn (): bool => $owner->hasTerm('category', $categoryA)))
        ->toThrow(TenantBoundaryViolation::class);
});

it('isolates locale rows and owner force deletion cleanup', function (): void {
    $scenario = TaxonomyTenancyScenario::install();
    $termA = $scenario->term($scenario::A, 'localized');
    $termB = $scenario->term($scenario::B, 'localized');
    /** @var Post $owner */
    $owner = $scenario->owner($scenario::A);
    $scenario->run($scenario::A, fn () => app(AttachTermsAction::class)->execute($owner, 'tag', [$termA]));

    $termA->setRelation('translations', $termB->translations);
    expect(fn () => $scenario->run($scenario::A, fn (): string => $termA->displayName('en')))
        ->toThrow(TenantBoundaryViolation::class);

    $scenario->run($scenario::A, fn () => $owner->delete());
    expect($scenario->run($scenario::A, fn (): int => Term::query()->findOrFail($termA->id)->attachments()->count()))
        ->toBe(0);
});

it('prunes only the selected tenant vocabulary', function (): void {
    $scenario = TaxonomyTenancyScenario::install();
    $termA = $scenario->term($scenario::A, 'orphan-a');
    $termB = $scenario->term($scenario::B, 'orphan-b');

    $this->artisan('nvl:taxonomy:prune', [
        'taxonomy' => 'tag',
        '--tenant' => $scenario::A,
        '--force' => true,
    ])->assertSuccessful();

    expect($scenario->run($scenario::A, fn (): bool => Term::query()->whereKey($termA->id)->exists()))->toBeFalse()
        ->and($scenario->run($scenario::B, fn (): bool => Term::query()->whereKey($termB->id)->exists()))->toBeTrue();
});
