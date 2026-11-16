<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Enums\SystemActor;
use Nvl\Taxonomy\Contracts\TaxonomyMembershipPolicy;
use Nvl\Taxonomy\Contracts\TaxonomyMembershipProvider;
use Nvl\Taxonomy\Enums\DeleteTermStrategy;
use Nvl\Taxonomy\Enums\TaxonomyMembershipMode;
use Nvl\Taxonomy\Enums\TaxonomyMembershipOperation;
use Nvl\Taxonomy\Exceptions\InvalidTermOperationException;
use Nvl\Taxonomy\Models\MembershipMatch;
use Nvl\Taxonomy\Models\Tag;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Services\TaxonomyAssignments;
use Nvl\Taxonomy\Services\TaxonomyMemberOrder;
use Nvl\Taxonomy\Services\TaxonomyOwnerRegistry;
use Nvl\Taxonomy\Services\TaxonomyTerms;
use Nvl\Taxonomy\Services\TermAttachmentWriter;
use Nvl\Taxonomy\Support\TaxonomyDefinition;
use Nvl\Taxonomy\Support\TaxonomyMembershipProposal;
use Nvl\Taxonomy\Tests\Fixtures\BatchArchivedPost;
use Nvl\Taxonomy\Tests\Fixtures\Post;
use Nvl\Taxonomy\Tests\TestCase;

uses(TestCase::class);

/** Bind a policy that answers with a fixed mode and records every reviewed proposal. */
function bindMembershipAuthorityPolicy(TaxonomyMembershipMode $mode): object
{
    $policy = new class($mode) implements TaxonomyMembershipPolicy
    {
        /** @var list<TaxonomyMembershipProposal> */
        public array $reviewed = [];

        /** @var list<string> Owner keys reviewed, in order. */
        public array $owners = [];

        /** Owner key whose review is denied with Shadow, whatever the mode. */
        public ?string $deny = null;

        public function __construct(public TaxonomyMembershipMode $mode) {}

        public function mode(Model $owner, TaxonomyDefinition $taxonomy, Authenticatable|SystemActor $actor, TaxonomyMembershipProposal $proposal): TaxonomyMembershipMode
        {
            $this->reviewed[] = $proposal;
            $this->owners[] = (string) $owner->getKey();

            return (string) $owner->getKey() === $this->deny ? TaxonomyMembershipMode::Shadow : $this->mode;
        }
    };
    app()->instance(TaxonomyMembershipPolicy::class, $policy);

    return $policy;
}

/** Build a provider that proposes a fixed source and term list, optionally failing to record. */
function membershipAuthorityProvider(string $source, array $terms, bool $reject = false): TaxonomyMembershipProvider
{
    return new class($source, $terms, $reject) implements TaxonomyMembershipProvider
    {
        public function __construct(private string $source, private array $terms, private bool $reject) {}

        public function propose(Model $owner, TaxonomyDefinition $taxonomy, Authenticatable|SystemActor $actor): TaxonomyMembershipProposal
        {
            return new TaxonomyMembershipProposal($this->source, $this->terms);
        }

        public function record(Model $owner, TaxonomyDefinition $taxonomy, Authenticatable|SystemActor $actor, TaxonomyMembershipProposal $proposal, TaxonomyMembershipMode $mode): void
        {
            if ($this->reject) {
                throw new RuntimeException('Provider could not record the decision.');
            }
        }
    };
}

/** @return array<string, string|null> */
function membershipAuthoritySources(Post $post): array
{
    return Termable::query()->where('termable_id', (string) $post->getKey())->with('term')->get()
        ->mapWithKeys(static fn (Termable $attachment): array => [$attachment->term->slug => $attachment->membership_source])
        ->sortKeys()->all();
}

it('enforces authority for every operation', function (Closure $operation, TaxonomyMembershipOperation $expected, Closure $resulting): void {
    Gate::define('taxonomy.reorder-members', static fn (mixed $actor): bool => $actor === SystemActor::Background);
    $actor = SystemActor::Background;
    $post = Post::create(['title' => 'Guarded']);
    app(TaxonomyAssignments::class)->sync($post, 'tag', ['alpha', 'beta'], $actor);
    $alpha = Tag::query()->where('slug', 'alpha')->sole();
    $beta = Tag::query()->where('slug', 'beta')->sole();
    $policy = bindMembershipAuthorityPolicy(TaxonomyMembershipMode::Shadow);

    expect(fn () => $operation($post, $alpha, $beta))->toThrow(InvalidTermOperationException::class);

    $reviewed = $policy->reviewed;
    expect($reviewed)->toHaveCount(1)
        ->and($reviewed[0]->operation)->toBe($expected)
        ->and($reviewed[0]->source)->toBe('manual')
        ->and(array_map(static fn (Term|string $term): string => $term instanceof Term ? $term->id : $term, $reviewed[0]->terms))
        ->toBe($resulting($alpha, $beta))
        ->and($post->fresh()->tags->pluck('slug')->all())->toBe(['alpha', 'beta'])
        ->and(Tag::query()->orderBy('slug')->pluck('slug')->all())->toBe(['alpha', 'beta'])
        ->and(Termable::query()->whereNotNull('member_position')->exists())->toBeFalse();

    $policy->mode = TaxonomyMembershipMode::Apply;
    $operation($post, $alpha->fresh(), $beta->fresh());

    expect($policy->reviewed)->toHaveCount(2);
})->with([
    'attach' => [
        static fn (Post $post) => app(TaxonomyAssignments::class)->attach($post, 'tag', ['gamma'], SystemActor::Background),
        TaxonomyMembershipOperation::Attach,
        static fn (Tag $alpha, Tag $beta): array => [$alpha->id, $beta->id, 'gamma'],
    ],
    'detach' => [
        static fn (Post $post) => app(TaxonomyAssignments::class)->detach($post, 'tag', ['alpha'], SystemActor::Background),
        TaxonomyMembershipOperation::Detach,
        static fn (Tag $alpha, Tag $beta): array => [$beta->id],
    ],
    'sync' => [
        static fn (Post $post) => app(TaxonomyAssignments::class)->sync($post, 'tag', ['gamma'], SystemActor::Background),
        TaxonomyMembershipOperation::Sync,
        static fn (): array => ['gamma'],
    ],
    'merge' => [
        static fn (Post $post, Tag $alpha, Tag $beta) => app(TaxonomyTerms::class)->merge($alpha, $beta, SystemActor::Background, $alpha->revision, $beta->revision),
        TaxonomyMembershipOperation::Merge,
        static fn (Tag $alpha, Tag $beta): array => [$beta->id],
    ],
    'delete' => [
        static fn (Post $post, Tag $alpha) => app(TaxonomyTerms::class)->delete($alpha, $alpha->revision, SystemActor::Background, DeleteTermStrategy::Detach),
        TaxonomyMembershipOperation::Delete,
        static fn (Tag $alpha, Tag $beta): array => [$beta->id],
    ],
    'reorder' => [
        static fn (Post $post, Tag $alpha) => app(TaxonomyMemberOrder::class)->reorder('tag', $alpha->id, [
            Termable::query()->where('term_id', $alpha->id)->sole()->id => 0,
        ], SystemActor::Background),
        TaxonomyMembershipOperation::Reorder,
        static fn (Tag $alpha, Tag $beta): array => [$alpha->id, $beta->id],
    ],
    'direct internal writer call' => [
        static fn (Post $post) => app(TermAttachmentWriter::class)->sync($post, 'tag', ['gamma'], SystemActor::Background),
        TaxonomyMembershipOperation::Sync,
        static fn (): array => ['gamma'],
    ],
]);

it('stores shadow matches without creating terms', function (): void {
    $actor = SystemActor::Background;
    $post = Post::create(['title' => 'Shadowed']);
    $assignments = app(TaxonomyAssignments::class);
    $assignments->sync($post, 'tag', ['existing'], $actor);
    $provider = membershipAuthorityProvider('shopify', ['brand-new', 'existing']);

    expect(fn () => $assignments->reconcile($post, 'tag', $provider, $actor))->toThrow(BindingResolutionException::class);

    $policy = bindMembershipAuthorityPolicy(TaxonomyMembershipMode::Shadow);

    expect($assignments->reconcile($post, 'tag', $provider, $actor))->toBe(TaxonomyMembershipMode::Shadow);

    $match = MembershipMatch::query()->sole();
    expect($policy->reviewed[0]->operation)->toBe(TaxonomyMembershipOperation::Reconcile)
        ->and($match->owner_type)->toBe($post->getMorphClass())
        ->and($match->owner_id)->toBe((string) $post->getKey())
        ->and($match->taxonomy)->toBe('tag')
        ->and($match->source)->toBe('shopify')
        ->and($match->proposal)->toBe(['brand-new', 'existing'])
        ->and($match->mode)->toBe(TaxonomyMembershipMode::Shadow)
        ->and($match->owner->is($post))->toBeTrue()
        ->and(Tag::query()->where('slug', 'brand-new')->exists())->toBeFalse()
        ->and(membershipAuthoritySources($post))->toBe(['existing' => null]);
});

it('stores empty shadow proposals, isolated by owner and source', function (): void {
    $actor = SystemActor::Background;
    $first = Post::create(['title' => 'First']);
    $second = Post::create(['title' => 'Second']);
    $assignments = app(TaxonomyAssignments::class);
    $assignments->sync($first, 'tag', ['kept'], $actor);
    bindMembershipAuthorityPolicy(TaxonomyMembershipMode::Shadow);

    foreach ([$first, $second] as $post) {
        $assignments->reconcile($post, 'tag', membershipAuthorityProvider('shopify', ['proposed']), $actor);
        $assignments->reconcile($post, 'tag', membershipAuthorityProvider('erp', []), $actor);
    }
    $assignments->reconcile($first, 'tag', membershipAuthorityProvider('shopify', []), $actor);

    $matches = MembershipMatch::query()->orderBy('owner_id')->orderBy('source')->get()
        ->map(static fn (MembershipMatch $match): array => [$match->owner_id, $match->source, $match->proposal])->all();
    expect($matches)->toBe([
        [(string) $first->getKey(), 'erp', []],
        [(string) $first->getKey(), 'shopify', []],
        [(string) $second->getKey(), 'erp', []],
        [(string) $second->getKey(), 'shopify', ['proposed']],
    ])
        ->and($first->fresh()->tags->pluck('slug')->all())->toBe(['kept'])
        ->and($second->fresh()->tags)->toBeEmpty();
});

it('manual sync clears the source even when positions are unchanged', function (): void {
    $actor = SystemActor::Background;
    $post = Post::create(['title' => 'Relabelled']);
    $assignments = app(TaxonomyAssignments::class);
    bindMembershipAuthorityPolicy(TaxonomyMembershipMode::Apply);
    $assignments->reconcile($post, 'tag', membershipAuthorityProvider('shopify', ['a', 'b']), $actor);
    expect(membershipAuthoritySources($post))->toBe(['a' => 'shopify', 'b' => 'shopify']);
    $positions = Termable::query()->orderBy('position')->pluck('position', 'id')->all();

    $assignments->sync($post, 'tag', ['a', 'b'], $actor);

    expect(membershipAuthoritySources($post))->toBe(['a' => null, 'b' => null])
        ->and(Termable::query()->orderBy('position')->pluck('position', 'id')->all())->toBe($positions)
        ->and(MembershipMatch::query()->sole()->mode)->toBe(TaxonomyMembershipMode::Apply);
});

it('attach keeps untouched sources', function (): void {
    $actor = SystemActor::Background;
    $post = Post::create(['title' => 'Mixed provenance']);
    $assignments = app(TaxonomyAssignments::class);
    $assignments->sync($post, 'tag', ['legacy'], $actor);
    bindMembershipAuthorityPolicy(TaxonomyMembershipMode::Apply);
    $assignments->reconcile($post, 'tag', membershipAuthorityProvider('shopify', ['legacy', 'ruled']), $actor);
    Termable::query()->whereHas('term', static fn ($query) => $query->where('slug', 'legacy'))->update(['membership_source' => null]);

    $assignments->attach($post, 'tag', ['manual'], $actor);
    expect(membershipAuthoritySources($post))->toBe(['legacy' => null, 'manual' => null, 'ruled' => 'shopify']);

    $assignments->detach($post, 'tag', ['manual'], $actor);
    expect(membershipAuthoritySources($post))->toBe(['legacy' => null, 'ruled' => 'shopify']);

    $assignments->attach($post, 'tag', ['ruled'], $actor);
    expect(membershipAuthoritySources($post))->toBe(['legacy' => null, 'ruled' => null]);
});

it('rolls back memberships and the match when record() throws', function (): void {
    $actor = SystemActor::Background;
    $post = Post::create(['title' => 'Atomic']);
    $assignments = app(TaxonomyAssignments::class);
    $assignments->sync($post, 'tag', ['existing'], $actor);
    bindMembershipAuthorityPolicy(TaxonomyMembershipMode::Apply);

    expect(fn () => $assignments->reconcile($post, 'tag', membershipAuthorityProvider('shopify', ['matched'], reject: true), $actor))
        ->toThrow(RuntimeException::class, 'could not record');

    expect(MembershipMatch::query()->exists())->toBeFalse()
        ->and(membershipAuthoritySources($post))->toBe(['existing' => null])
        ->and(Tag::query()->where('slug', 'matched')->exists())->toBeFalse();
});

/** Give the Post fixture table a soft-delete column and register the archived owner alias. */
function enableArchivedMembershipOwners(): void
{
    Schema::table((new Post)->getTable(), static fn (Blueprint $table) => $table->softDeletes('archived_at'));
    app(TaxonomyOwnerRegistry::class)->register('archived_posts', BatchArchivedPost::class);
}

/** @return list<string> */
function membershipAuthorityTermIds(TaxonomyMembershipProposal $proposal): array
{
    return array_map(static fn (Term|string $term): string => $term instanceof Term ? $term->id : $term, $proposal->terms);
}

it('reviews trashed owners and skips vanished owners when a term is deleted', function (): void {
    enableArchivedMembershipOwners();
    $actor = SystemActor::Background;
    $trashed = BatchArchivedPost::create(['title' => 'Trashed']);
    $vanished = Post::create(['title' => 'Vanished']);
    app(TaxonomyAssignments::class)->sync($trashed, 'tag', ['laravel'], $actor);
    app(TaxonomyAssignments::class)->sync($vanished, 'tag', ['laravel'], $actor);
    $trashed->delete();
    DB::table($vanished->getTable())->where('id', $vanished->getKey())->delete();
    $policy = bindMembershipAuthorityPolicy(TaxonomyMembershipMode::Apply);
    $tag = Tag::query()->where('slug', 'laravel')->sole();

    expect(app(TaxonomyTerms::class)->delete($tag, $tag->revision, $actor, DeleteTermStrategy::Detach))->toBeTrue()
        ->and(Termable::query()->exists())->toBeFalse()
        ->and($policy->owners)->toBe([(string) $trashed->getKey()])
        ->and(membershipAuthorityTermIds($policy->reviewed[0]))->toBe([]);
});

it('rejects a structural write when one of several owners is denied', function (): void {
    $actor = SystemActor::Background;
    $first = Post::create(['title' => 'First']);
    $second = Post::create(['title' => 'Second']);
    app(TaxonomyAssignments::class)->sync($first, 'tag', ['alpha'], $actor);
    app(TaxonomyAssignments::class)->sync($second, 'tag', ['alpha', 'beta'], $actor);
    $alpha = Tag::query()->where('slug', 'alpha')->sole();
    $beta = Tag::query()->where('slug', 'beta')->sole();
    $policy = bindMembershipAuthorityPolicy(TaxonomyMembershipMode::Apply);
    $policy->deny = (string) $second->getKey();

    expect(fn () => app(TaxonomyTerms::class)->merge($alpha, $beta, $actor, $alpha->revision, $beta->revision))
        ->toThrow(InvalidTermOperationException::class);

    expect($policy->owners)->toBe([(string) $first->getKey(), (string) $second->getKey()])
        ->and(membershipAuthorityTermIds($policy->reviewed[1]))->toBe([$beta->id])
        ->and($first->fresh()->tags->pluck('slug')->all())->toBe(['alpha'])
        ->and($second->fresh()->tags->pluck('slug')->all())->toBe(['alpha', 'beta'])
        ->and(Tag::query()->whereKey($alpha->id)->exists())->toBeTrue();
});

it('reviews owners of removed descendants on cascade and only the deleted term on reparent', function (DeleteTermStrategy $strategy, array $reviewedTitles): void {
    $actor = SystemActor::Background;
    $terms = app(TaxonomyTerms::class);
    $root = $terms->create(['taxonomy' => 'category', 'slug' => 'root', 'translations' => ['en' => ['name' => 'Root']]], $actor);
    $child = $terms->create(['taxonomy' => 'category', 'slug' => 'child', 'parent_id' => $root->id, 'translations' => ['en' => ['name' => 'Child']]], $actor);
    $other = $terms->create(['taxonomy' => 'category', 'slug' => 'other', 'translations' => ['en' => ['name' => 'Other']]], $actor);
    $posts = [];
    foreach (['root' => $root, 'child' => $child, 'other' => $other] as $title => $term) {
        $posts[$title] = Post::create(['title' => $title]);
        app(TaxonomyAssignments::class)->sync($posts[$title], 'category', [$term], $actor);
    }
    $policy = bindMembershipAuthorityPolicy(TaxonomyMembershipMode::Apply);

    expect($terms->delete($root, $root->fresh()->revision, $actor, $strategy, $strategy === DeleteTermStrategy::Reparent ? $other->id : null))->toBeTrue()
        ->and($policy->owners)->toBe(array_map(static fn (string $title): string => (string) $posts[$title]->getKey(), $reviewedTitles))
        ->and(array_map(membershipAuthorityTermIds(...), $policy->reviewed))->each->toBe([])
        ->and(collect($policy->reviewed)->pluck('operation')->unique()->all())->toBe([TaxonomyMembershipOperation::Delete]);
})->with([
    'cascade' => [DeleteTermStrategy::Cascade, ['root', 'child']],
    'reparent' => [DeleteTermStrategy::Reparent, ['root']],
]);

it('needs no review for a restricted delete', function (): void {
    $tag = app(TaxonomyTerms::class)->create(['taxonomy' => 'tag', 'slug' => 'unused', 'translations' => ['en' => ['name' => 'Unused']]], SystemActor::Background);
    $policy = bindMembershipAuthorityPolicy(TaxonomyMembershipMode::Shadow);

    expect(app(TaxonomyTerms::class)->delete($tag, $tag->revision, SystemActor::Background))->toBeTrue()
        ->and($policy->reviewed)->toBe([]);
});

it('removes an owner match rows exactly when it removes the owner attachments', function (): void {
    enableArchivedMembershipOwners();
    $actor = SystemActor::Background;
    $post = BatchArchivedPost::create(['title' => 'Matched']);
    $other = Post::create(['title' => 'Other']);
    bindMembershipAuthorityPolicy(TaxonomyMembershipMode::Apply);
    foreach ([$post, $other] as $owner) {
        app(TaxonomyAssignments::class)->reconcile($owner, 'tag', membershipAuthorityProvider('shopify', ['ruled']), $actor);
    }

    $post->delete();
    expect(MembershipMatch::query()->count())->toBe(2)
        ->and(Termable::query()->count())->toBe(2);

    $post->forceDelete();
    expect(MembershipMatch::query()->sole()->owner_id)->toBe((string) $other->getKey())
        ->and(Termable::query()->sole()->termable_id)->toBe((string) $other->getKey());
});

it('refuses relationship writes while a membership policy is bound', function (): void {
    $post = Post::create(['title' => 'Related']);
    $tag = app(TaxonomyTerms::class)->create(['taxonomy' => 'tag', 'slug' => 'related', 'translations' => ['en' => ['name' => 'Related']]], SystemActor::Background);
    $post->tags()->attach($tag->id, ['taxonomy' => 'tag']);
    expect($post->tags()->count())->toBe(1);
    bindMembershipAuthorityPolicy(TaxonomyMembershipMode::Apply);

    expect(fn () => $post->tags()->attach($tag->id, ['taxonomy' => 'tag']))->toThrow(InvalidTermOperationException::class, 'Use TaxonomyAssignments')
        ->and(fn () => $post->tags()->detach($tag->id))->toThrow(InvalidTermOperationException::class, 'Use TaxonomyAssignments')
        ->and(fn () => $post->tags()->sync([]))->toThrow(InvalidTermOperationException::class, 'Use TaxonomyAssignments');
    expect($post->tags()->count())->toBe(1);
});

it('applies a writer reconciliation only after the policy answers apply', function (): void {
    $post = Post::create(['title' => 'Forged']);
    $policy = bindMembershipAuthorityPolicy(TaxonomyMembershipMode::Shadow);

    expect(app(TermAttachmentWriter::class)->reconcile($post, 'tag', new TaxonomyMembershipProposal('shopify', ['forged']), SystemActor::Background))
        ->toBe(TaxonomyMembershipMode::Shadow)
        ->and($policy->reviewed)->toHaveCount(1)
        ->and(Termable::query()->exists())->toBeFalse()
        ->and(Tag::query()->where('slug', 'forged')->exists())->toBeFalse();
});

it('shows the policy each attached term once', function (): void {
    $actor = SystemActor::Background;
    $post = Post::create(['title' => 'Repeated']);
    app(TaxonomyAssignments::class)->sync($post, 'tag', ['alpha'], $actor);
    $alpha = Tag::query()->where('slug', 'alpha')->sole();
    $policy = bindMembershipAuthorityPolicy(TaxonomyMembershipMode::Apply);

    app(TaxonomyAssignments::class)->attach($post, 'tag', ['alpha', 'Alpha', $alpha, 'fresh', 'fresh'], $actor);

    expect(membershipAuthorityTermIds($policy->reviewed[0]))->toBe([$alpha->id, 'fresh'])
        ->and($post->fresh()->tags->pluck('slug')->all())->toBe(['alpha', 'fresh']);
});
