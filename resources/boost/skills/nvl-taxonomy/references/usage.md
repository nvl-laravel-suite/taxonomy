# Taxonomy integration and recipes

Read [installation](installation.md) for setup and [API](api.md) for exact declarations.
Application class names in examples are host-owned.

## Register vocabularies and owners

`nvl-taxonomy.taxonomies` is the code-owned alias map. A declaration selects
`model`, `hierarchical`, `exclusive`, `open`, `max_depth`, `sort`, `allowed_owners`,
`metadata_rules`. Aliases are canonical lowercase identifiers; supported sorts
are `position`, `slug`, `created_at`. Defaults include open flat `tag` and closed,
hierarchical, exclusive `category`; configure real application choices explicitly.

```php
config([
    'nvl-taxonomy.owners.article' => \App\Models\Article::class,
    'nvl-taxonomy.taxonomies.topic' => [
        'model' => \Nvl\Taxonomy\Models\Term::class,
        'hierarchical' => true, 'exclusive' => false, 'open' => false,
        'max_depth' => 3, 'sort' => 'position',
        'allowed_owners' => ['article'], 'metadata_rules' => [],
    ],
]);
```

Set this configuration before the registry loads. Alternatively register a real
`TaxonomyDefinition` through `TaxonomyRegistry::register`, and owners through
`TaxonomyOwnerRegistry::register($alias,$model)`. Keep shared aliases and native
host morph identity consistent; registration is capability, not a permission grant.
An empty vocabulary owner allowlist permits registered owners, not arbitrary model
classes. Owners need the same effective connection as the configured Term root.

## Gates and private SQL policy

Provide host Gate rules for used `taxonomy.*` abilities: creation, update, deletion,
move, merge, assignment, read, resolve and rebuild according to the called service.
Use actual method declarations and policy arguments in [API](api.md), rather than
assuming all abilities receive the same context. SystemActor is explicit but still
passes the Gate. Human actors must be persisted Eloquent identities; canonical
reload discards caller roles/relations and rejects substituted identity/storage.

Create passes the vocabulary string. Update uses a locked canonical term plus
vocabulary; move/delete include the related destination where applicable. Merge
includes both canonical source and destination. Assignment passes the canonical
locked owner and vocabulary. Never derive permission from a submitted owner field.

Bind `Nvl\Taxonomy\Contracts\TaxonomyCatalogAuthorization` for private vocabulary
admission and `TaxonomyBatchAuthorization` for private batch/correlated visibility.
The native defaults admit registered capabilities and retain native model scopes;
they do not implement application private-content policy. Catalog authorization
receives explicit actor plus vocabulary. Batch methods all receive canonical actor
first and must use loaded facts/constrain SQL without per-owner queries:
`authorizeOwners`, `scopeAttachments`, `scopeTerms`, `scopeHostTerms`.

## Terms, translations and revisions

```php
use Nvl\Taxonomy\Services\TaxonomyTerms;
use Nvl\Translatable\Enums\TranslationSyncMode;

$terms = app(TaxonomyTerms::class);
$topic = $terms->create([
    'taxonomy' => 'topic', 'slug' => 'engineering',
    'translations' => ['en' => ['name' => 'Engineering']],
    'parent_id' => null, 'position' => 0, 'meta' => null,
], $editor);
$topic = $terms->update($topic, [
    'expected_revision' => $topic->revision,
    'translations' => ['en' => [
        'name' => 'Engineering', 'description' => 'Technical articles',
    ]],
], $editor, TranslationSyncMode::Patch);
$topic = $terms->move($topic, null, $editor, 1, $topic->revision);
```

Create's complete key allowlist is `taxonomy`, `slug`, `translations`, `parent_id`,
`position`, `meta`; an expected revision cannot be supplied for creation. Slugs
must already be canonical and unique among siblings in their vocabulary. The slug
generator is configurable under `nvl-taxonomy.slugs`, but service input is not an
unvalidated display label. Locale keys must be supported; each row allows only
required nonempty `name` and nullable `description`, including rows supplied on update.

Update adds required integer `expected_revision`, preserving omitted structural
fields and translations. The vocabulary is immutable; parent identity changes use
`move`. Patch keeps omitted locales; Replace needs an explicit locale map and
removes omitted locales. Nullable `meta` clears it. Null positions are invalid.
Apply registered metadata rules and byte/depth limits; do not rename `meta` to an
invented `metadata` payload property or move localized names onto the Term row.

`merge($source,$destination,$actor,$expectedSourceRevision,$expectedDestinationRevision)`
checks both current terms, moves/reconciles attachments/children under locks and
returns the refreshed destination. `validateMerge($source,$destination,$actor,
$expectedSourceRevision,$expectedDestinationRevision): void` checks the same revisioned
proposal and throws on invalid, stale or denied work. Supply both revisions again
when applying. `delete($term,$expectedRevision,$actor,
$strategy,$reparentTo)` defaults to `DeleteTermStrategy::Restrict`; explicitly choose
Detach/Reparent/Cascade only for the intended destructive operation. Preserve hierarchy,
owner/vocabulary, depth, sibling-slug and attachment conflict checks. `rebuild`
reconstructs positions with actor policy and optional dry-run.

## Assignments and reference resolution

```php
use Nvl\Taxonomy\Services\TaxonomyAssignments;
use Nvl\Taxonomy\Services\TaxonomyReader;

app(TaxonomyAssignments::class)->sync($article, 'topic', [$topic], $editor);
$batch = app(TaxonomyReader::class)->forOwners([$article], ['topic'], $viewer, 'en');
$tree = app(TaxonomyReader::class)->tree('topic', $viewer, 'en', 100);
```

Attach appends, detach returns a removal count, and sync replaces the supplied
ordered vocabulary assignment set atomically. Inputs are `list<Term|string>`;
persisted IDs/models identify exact terms. Every operation keeps owner locks and
one connection transaction, and an error cannot leave partial assignments.

`TaxonomyReader::resolve($taxonomy,$references,$actor)` resolves ordered normalized
references. Existing UUIDs do not create records. Missing slugs may create roots
only for registered open vocabularies and require separate Create authorization.
Ambiguous slugs, duplicate normalized references beyond policy and over-limit
requests fail instead of silently choosing another term. The configured bulk
reference bound is `nvl-taxonomy.limits.bulk_terms`.

`forOwners` admits at most 100 owners / 20 vocabularies. Its result is
`['owners'=>stdClass,'order'=>list<['type'=>string,'id'=>string]>]`; nested maps use
exact native morph type and owner key. Every requested owner/vocabulary has an
explicit list, including empty ones. Terms are native models with loaded copy and
`pivot_position`, retaining original order. Tree is bounded to at most 500 terms;
`term` resolves an exact UUID in a specified vocabulary, not a generic unscoped find.

`Nvl\Taxonomy\Concerns\HasTaxonomies` supplies owner relation/filter convenience.
Host taxonomy filter scopes such as `withAnyTerms($taxonomy,$values,$actor,$policy)`
and `withAllTerms` / `withoutTerms` require explicit actors.
`inCategory($category,$actor,$includeDescendants,$policy)` retains its descendant policy. Keep correlated host/term/attachment
SQL constraints grouped so caller OR clauses cannot escape privacy or Ownership.

## Model extension, isolation and verification

Set `nvl-taxonomy.models.term` before boot to a concrete Term subclass. A selected
custom root is used across vocabulary roots; preserve the vocabulary column/scopes,
UUID identity, required casts, hierarchy/persistence hooks, native ownership and
configured table/connection. Use observers/bootable traits and preserve inherited
hooks. Translation rows and attachment pivots remain native; host migrations own
new columns. An owner alias never automatically scopes or adopts existing records.

Native Ownership is optional for unscoped hosts. Owned storage needs explicit model
declarations, valid runtime context and reviewed adoption. There is no retired
Tenancy bridge or automatic legacy-schema conversion. No DTO/Filterable package
is required merely to use native taxonomy operations.

Domain writes retain source transactions and outer-commit events. Handle stale
terms, invalid hierarchy, duplicate sibling slugs, unsafe deletion, missing terms
and authorization failures at the consumer boundary, avoiding raw SQL/foreign IDs.
Test direct services for gate context, canonical actor/owner substitution, null and
translation modes, cycle/depth, merge rollback, assignment atomicity and bounded
batch/host-filter privacy; keep HTTP request/resource tests in the application.
