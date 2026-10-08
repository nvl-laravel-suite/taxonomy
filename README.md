# NVL Taxonomy — API and usage

## Quickstart

```sh
composer require nvl/taxonomy:^5.0
php artisan nvl:install taxonomy --dry-run
php artisan nvl:install taxonomy
```

Required NVL dependencies: `nvl/core` (`^5.0`), `nvl/translatable` (`^5.0`). Register the categories vocabulary and host authorization/owner capability. Use batched owner readers rather than querying package-owned capability relations.
Review the published common config, select one migration owner, and run schema preflight before existing-table upgrades. The installer does not enable features or run migrations. Follow the detailed installation and capability sections below before invoking a storage/provider operation.

Inject `Nvl\Taxonomy\Contracts\TaxonomyTreeContract` in a host service. After supplying the trusted inputs described above, the first public call is:

```php
use Nvl\Taxonomy\Contracts\TaxonomyTreeContract;

/** @var TaxonomyTreeContract $capability */
$result = $capability->for('categories');
```

Use the [event catalog](docs/events.md) and [Testing your app](#testing-your-app) below. The suite [getting-started guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/getting-started.md) provides a complete Comments host fixture; package archives retain their own local references.


[← NVL Laravel Suite](https://github.com/nvl-laravel-suite)

For support, [open an issue](https://github.com/nvl-laravel-suite/taxonomy/issues). For vulnerabilities, use
[private reporting](https://github.com/nvl-laravel-suite/taxonomy/security/advisories/new). See [Contributing](CONTRIBUTING.md).

See the [installation and publishing guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/installation.md) for Composer setup, configuration, migration ownership, and agent skills.

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/taxonomy:^5.0` |
| Module identifier | `nvl/taxonomy` |
| PHP namespace | `Nvl\Taxonomy` |
| Service provider | `Nvl\Taxonomy\Providers\TaxonomyServiceProvider` |
| Configuration | `config/nvl-taxonomy.php` |

## Purpose

`nvl/taxonomy` provides reusable translated vocabularies and hierarchical terms for Laravel 12–13 on PHP 8.4+. It supports categories, tags, ordered trees, typed metadata, polymorphic owner attachment, moves, merges, pruning, and deterministic localized display copy. It is not an arbitrary attribute, facets, or search engine.

The package depends on `nvl/core`, `nvl/tenancy`, and `nvl/translatable` inside the NVL family.

## Requirements and installation

```bash
composer require nvl/taxonomy:^5.0
php artisan migrate
```

Laravel auto-discovers `TaxonomyServiceProvider`. Optional publish tags are:

```bash
php artisan vendor:publish --tag=nvl-taxonomy-translations
php artisan vendor:publish --tag=nvl-taxonomy-config
php artisan vendor:publish --tag=nvl-taxonomy-migrations
php artisan vendor:publish --tag=nvl-taxonomy-skills
```

Clean-install migrations use UUID term identifiers, nullable UUID parent identifiers, dedicated term translations, and string-compatible owner identifiers. Set `nvl-taxonomy.migrations.enabled=false` during controlled adoption of existing tables.

Choose exactly one migration owner. For automatic vendor loading, leave
`nvl-taxonomy.migrations.enabled=true` and do not publish `nvl-taxonomy-migrations`.
For host-owned migrations, publish `nvl-taxonomy-migrations`, set
`nvl-taxonomy.migrations.enabled=false` before the first migration, and maintain
the copied files as application migrations. Never run both sources; Laravel
retimestamps published migrations.

## Register vocabularies and owners

Declare stable vocabulary rules in `config/nvl-taxonomy.php`:

```php
'taxonomies' => [
    'topics' => [
        'model' => \Nvl\Taxonomy\Models\Term::class,
        'hierarchical' => true,
        'exclusive' => false,
        'open' => true,
        'max_depth' => 5,
        'sort' => 'position',
        'allowed_owners' => ['articles'],
        'metadata_rules' => [
            'color' => ['nullable', 'string', 'max:20'],
        ],
    ],
],
'owners' => [
    'articles' => Article::class,
],
```

`TaxonomyRegistry` and `TaxonomyOwnerRegistry` reject invalid and duplicate registrations. Owner aliases are installed in Laravel's morph map and persisted in `termable_type`, so refactoring a PHP namespace does not corrupt attachment identity. Open vocabularies allow term creation through authorized application flows; closed vocabularies require an existing registered term.

Register every concrete owner class that can receive terms. A base-class alias is not inherited by subclasses because Laravel's morph map identifies concrete classes exactly.

## Create a translated term

```php
use Nvl\Taxonomy\Actions\CreateTermAction;
use Nvl\Taxonomy\Data\MutateTermPayload;

$term = app(CreateTermAction::class)->execute(new MutateTermPayload(
    taxonomy: 'topics',
    slug: 'engineering',
    translations: [
        'en' => ['name' => 'Engineering'],
        'bg' => ['name' => 'Инженерство'],
    ],
    parentId: null,
    position: 10,
    meta: ['color' => 'blue'],
));
```

Taxonomy, parent, slug, position, metadata, and owner attachments are structural. Name and description exist only in `terms_i18n` and resolve through `nvl/translatable`. Slugs are canonical and do not change with locale. UUID-shaped slugs are reserved for unambiguous identifier references.

Updates use `UpdateTermAction` and require `expectedRevision`. Stale writes fail instead of silently overwriting newer changes.

## Hierarchy

Use `MoveTermAction` for reparenting. It rejects:

- parents from another vocabulary;
- a term as its own parent;
- ancestor/descendant cycles;
- hierarchy on a flat vocabulary;
- moves beyond the configured maximum depth;
- duplicate sibling slugs.

`app(TaxonomyTree::class)->for($taxonomy, $locale)` delegates tree ordering and translation loading to the package reader and returns Term identity/result handles. The tree's loaded child/translation relationships describe internal storage state and do not grant consumer traversal or serialization. Obtain owner term display projections through `ListOwnerTaxonomyTermsContract`, or an explicitly authorized host adapter for a custom tree projection; do not query/eager-load package models or recurse through their relations in an API transformer.

## Attach terms

Use `AttachTermsAction`, `DetachTermsAction`, or `SyncTermAttachmentsAction` with a registered owner. Do not write the polymorphic attachment table directly. Vocabulary rules enforce allowed owner aliases and exclusive membership where configured.

Attachment actions serialize each owner/vocabulary set with Laravel atomic locks. Production nodes must use a shared lock-capable cache store.

`MergeTermsAction` moves attachments and eligible children under a connection-correct transaction and requires the expected revisions of both terms. `DeleteTermAction` requires an expected revision and a `DeleteTermStrategy`; it rejects unsafe deletion when attachments or children cannot be handled by that strategy.

## Central translation management

Taxonomy registers its term resource with `TranslationResourceRegistry` for
central gathering, coverage, and reads using the package field whitelist, query
scope, authorization, and version hash. Terms declare
`TranslationMutationPolicy::DomainActionOnly`: generic central sync and locale
deletion actions reject term writes.

Write localized names and descriptions through `CreateTermAction` or
`UpdateTermAction` using the `translations` field of `MutateTermPayload`.
For updates, provide the expected term revision. Pass `mode: TranslationSyncMode::Replace` to
`UpdateTermAction::execute()` with the retained locale map to remove omitted
locales. This keeps localized changes inside Taxonomy's validation, revision,
and event workflow. Consumer applications must authorize these domain actions
before accepting user input.

Generate TypeScript declarations under `Nvl.Taxonomy.*`:

```bash
php artisan nvl:data:types:generate
php artisan nvl:data:types:check
```

## Configuration

Important groups are:

- `owners`: stable aliases to Eloquent model classes;
- `taxonomies`: hierarchy, openness, exclusivity, depth, sorting, owner allowlist, and metadata validation;
- `table_names` and `storage.connection`;
- `migrations.enabled`;
- `limits.metadata_bytes`, `metadata_depth`, `description_chars`, and `bulk_terms`;
- `transactions.attempts` for deadlock retries;
- `locks.seconds` and `locks.wait_seconds` for attachment-set serialization;
- `slugs.generator` and `slugs.locale`.

Metadata is bounded and validated; it is not an arbitrary query language.

## Commands

```bash
php artisan nvl:taxonomy:doctor --strict --format=json
php artisan nvl:taxonomy:rebuild --dry-run
php artisan nvl:taxonomy:merge --help
php artisan nvl:taxonomy:prune --dry-run
php artisan nvl:taxonomy:prune category --include-closed --force
```

Doctor verifies required columns and unique indexes plus registry, connection, parent, attachment, translation, cycle, and depth invariants. Dry-run modes do not mutate state. Pruning protects closed vocabularies unless `--include-closed` is supplied explicitly.

## Database and adoption

The schema indexes vocabulary/parent/slug, tree order, owner/type, term attachment, and locale lookups. Package migrations honor configured table names and connection where supported.

## Tenant-local trees

Installing Taxonomy also installs the inert `nvl/tenancy` library. With tenancy
enabled, register every taxonomy owner as a canonical tenant resource and use
`Nvl\Taxonomy\Models\Term` as each configured vocabulary model. Run reviewed
adoption for the `taxonomy` package before tenant traffic; it copies split trees
and translations, rewrites owner attachments, and activates tenant-leading
parent and attachment constraints. Vocabulary aliases remain global immutable
configuration while every term, translation, and attachment is tenant-local.

Take a pre-cutover backup and treat the reviewed split/destination mapping as
immutable. An interrupted run may resume after source or schema repair that is
consistent with that mapping. Changed tenant or destination assignments require
restore and a new reviewed prepare; dropping tenant columns is not rollback once
duplicate slugs and copied trees exist. Cleanup traverses one tenant-owned graph
at a time and must not prune another tenant's attachments.

Maintenance is explicit per tenant, for example:

```bash
php artisan nvl:taxonomy:rebuild category --tenant=<tenant-uuid> --dry-run
php artisan nvl:taxonomy:prune tag --tenant=<tenant-uuid> --dry-run
```

Attachment Actions and `ListOwnerTaxonomyTermsContract` support the package's dedicated taxonomy storage boundary. Owner-to-term lazy/eager relations and inverse `Term::entries()` joins are package-internal storage behavior. Consumers use the bounded reader for display and the authorized C1 `withAnyTerms`, `withAllTerms`, `withoutTerms`, or `inCategory` host scopes with their explicit batch policy for filtering. Those correlated host scopes require the same physical database; raw relation traversal does not become supported merely because connections match.

For an existing schema, disable automatic migrations and run the doctor. Convert root sentinels to `null`, backfill dedicated translation rows, and resolve identifier differences in an application-owned reversible bridge. A table-name match is not schema compatibility.

## Authorization, caching, and failures

This package ships no management routes. Applications authorize Action calls and any API built over them. Registry aliases and vocabulary rules are allowlists, not authorization by themselves.

`TermChanged` mutation events implement `ShouldDispatchAfterCommit`. Unknown vocabularies, invalid metadata, stale revisions, ambiguous slugs, hierarchy violations, and unsafe deletes are distinct failures.

## Batched owner reads

Resolve `Nvl\Taxonomy\Contracts\ListOwnerTaxonomyTermsContract` for many-owner display reads. It reloads registered persisted owners by concrete class, keeps their global scopes and live-record guards, and admits one canonical connection and tenant context. Supply at most 100 input owners and 20 registered vocabulary names. Owner capability labels remain registration references; stored and returned identities use each owner's native Laravel morph class and string key. Declared vocabulary owner allowlists remain mandatory. The model's relationship configuration does not grant an additional capability.

```php
use Nvl\Taxonomy\Contracts\ListOwnerTaxonomyTermsContract;

$result = app(ListOwnerTaxonomyTermsContract::class)->execute(
    $articles,
    ['tag', 'category'],
    locale: 'bg',
);

foreach ($articles as $article) {
    $terms = $result->owners->{$article->getMorphClass()}->{(string) $article->getKey()};
    foreach ($terms->vocabularies->tag as $term) {
        $labels[] = $term->name;
    }
}
```

The result supplies an object at both owner map levels, an object mapping requested vocabularies to explicit term lists, and separate first-request identity order. Numeric owner keys remain JSON object properties. Term DTOs contain only id, vocabulary, structural slug, nullable parentId, attachment position, localized name, and nullable description. Position comes from the attachment pivot. No models, relationships, tree paths, metadata, or lazy translation queries enter the public result. Localization uses the declared finite locale chain, including deterministic AnyAvailable fallback when configured, and preserves non-null empty copy.

The default `Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization` adapter preserves explicitly registered owner/vocabulary capability admission. Bind a host SQL adapter for private deployments; provider defaults use bindIf and preserve existing host bindings. Its four query-free methods authorize the loaded owner batch, constrain attachments, constrain terms, and independently express complete correlated host-owner/attachment/term visibility. Each SQL method receives its own nested AND group inside mandatory exact identities, registered vocabulary, live-record, connection, and tenant predicates. Imperative per-owner policies require an explicit SQL adapter; do not call single-owner actions or perform queries inside policy methods.

Attachment ranking transfers at most 101 facts per exact owner/vocabulary group and throws `Nvl\Taxonomy\Exceptions\TaxonomyBatchReadException` on visible overflow before loading term or translation payloads. The probe ceiling is 202,000 attachments and the successful payload ceiling is 200,000 term DTOs. Prefer fewer requested vocabularies for ordinary lists. Terms and translations load separately per vocabulary, with at most 10,000 term IDs in each vocabulary query, keeping PostgreSQL bindings bounded. Supported window engines are SQLite, PostgreSQL, MySQL, and MariaDB.

Measured total SQL counts stay fixed at 1, 25, and 100 owners on one concrete owner class, with localized populated vocabularies:

| Populated vocabularies | Disabled warm | Disabled cold | Enabled adopted Tenancy |
| --- | --- | --- | --- |
| One | 4 | 5 | 4 |
| Two | 6 | 7 | 6 |

The disabled measurement loads the inert Tenancy library; cold includes its first installation-state probe. Every additional concrete owner class adds one canonical reload query. Two owner classes measured 5/7 warm queries for one/two populated vocabularies; registered custom term models retain the same two loads per populated vocabulary. An empty owner input performs zero SQL. Empty admitted vocabularies skip their term/translation payload loads. Category traversal has a separate bounded hierarchy budget.

The existing withAnyTerms, withAllTerms, withoutTerms, and inCategory host scopes accept an optional explicit batch policy. They retain selections and unrelated predicates, reject altered builder connections/FROM clauses/unions, and restore registered tenant and native or custom soft-delete guards even when callers remove global scopes. Any with no references matches nothing; All and Without with no references preserve the admitted host set. All checks each reference independently, so a UUID and slug may identify the same attached term. Category filters reload the root through its registered scoped term model and policy, then traverse only visible child sets bounded by the configured bulk-term limit; overflow fails explicitly instead of loading the whole vocabulary.

Host filters require the owner table identifier to differ from both the requested vocabulary's registered term-model table and the canonical attachment table. They conservatively reject matching table basenames without case sensitivity, including schema-qualified and configured physical names and same-named tables in distinct schemas. Nonempty Any/All/Without and every Category filter throw `Nvl\Taxonomy\Exceptions\TaxonomyBatchReadException` before storage SQL for those unsupported correlations. Empty-reference scopes keep their admitted host semantics because they need no term correlation. Use `Nvl\Taxonomy\Contracts\ListOwnerTaxonomyTermsContract` for bounded display reads of overlapping owners; its captured native identity pairs remain supported.

## Verification

The package tests cover UUID identifiers, stable morph aliases, translation fallback, slug stability, tree order, cycles, subtree depth, moves, merges, attachments, exclusivity, deletion policies, maintenance safety, and configured-connection behavior. The local Dagger gate runs PHP 8.4/Laravel 13 suites and MySQL/PostgreSQL persistence contracts. Other declared platforms need separate compatibility evidence.

See [UPGRADING.md](UPGRADING.md), [SECURITY.md](SECURITY.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [CHANGELOG.md](CHANGELOG.md).

## Injectable workflow contracts

Constructor-inject focused interfaces from `Nvl\Taxonomy\Contracts` when composing host workflows. Each interface retains the native Action’s complete `execute` parameters, defaults, return type, and documented generic/shape result. Concrete Actions remain directly usable in major 5.

```php
use Nvl\Taxonomy\Contracts\CreateTermContract;
use Nvl\Taxonomy\Data\MutateTermPayload;
use Nvl\Taxonomy\Models\Term;

final readonly class CreateTermWorkflow
{
    public function __construct(private CreateTermContract $workflow) {}

    public function execute(MutateTermPayload $data): Term
    {
        return $this->workflow->execute($data);
    }
}
```

The provider installs conditional transient defaults (`bindIf`) for the following selected workflows. A host interface binding registered before package discovery is retained; a later binding/instance replacement is used by newly resolved host services. Keep authorization, validation, query ownership, and mutation behavior inside the owning package workflow.

| Contract | Native implementation |
| --- | --- |
| `AttachTermsContract` | `AttachTermsAction` |
| `CreateTermContract` | `CreateTermAction` |
| `DeleteTermContract` | `DeleteTermAction` |
| `DetachTermsContract` | `DetachTermsAction` |
| `ListOwnerTaxonomyTermsContract` | `ListOwnerTaxonomyTermsAction` |
| `MergeTermsContract` | `MergeTermsAction` |
| `MoveTermContract` | `MoveTermAction` |
| `SyncTermAttachmentsContract` | `SyncTermAttachmentsAction` |
| `UpdateTermContract` | `UpdateTermAction` |

`TaxonomyTreeContract::for(string $taxonomy, ?string $locale = null)` returns `Illuminate\Database\Eloquent\Collection<int, Nvl\Taxonomy\Models\Term>`. `TermResolverContract::resolve(string $taxonomy, array $references, bool $createMissing = true)` accepts `list<Term|string>` and returns `list<Term>`. Inject these transient contracts for native localized tree/reference workflows. Resolution may create permitted open-vocabulary roots when `createMissing` is true. Registered Term model handles remain native package identities.

## Supported PHP usage

The source `@api` declarations identify supported workflows, extension contracts, and value types. Public members marked `@internal` and untagged implementation types remain package-owned. Concrete Actions retain their existing constructors, qualifiers, and `execute()` signatures.

A package model returned or accepted by a public workflow is an identity/result handle. Use its declared type and `getKey()`, `getKeyName()`, `getMorphClass()`, `getRouteKey()`, `getRouteKeyName()`, `is()`, `isNot()`, and `relationLoaded()`. Read only explicitly declared in-memory `@nvl-consumer-read` fields; ordinary model PHPDocs and fillable attributes do not grant consumer reads. Obtain display projections through public reads. Persistence, additional model queries, relation access/loading, and generic model serialization are outside this contract. Host-model queries remain available, while traversal or aggregates of package capability relations require the package public reader or authorized adapter.

The current readable handle fields are `Term`: `id`, `taxonomy`, `slug`, `position`, `revision`, `created_at`, `updated_at`. All other package model handles have no readable attribute grant.

## Shared owner identity

Declare a model once in `config/nvl-core.php`:

```php
'owners' => [Article::class],
```

Enable this package capability separately in `config/nvl-taxonomy.php`:

```php
'owners' => [Article::class],
```

The shared alias must match the Taxonomy owner identity. Registering an identity does not expand vocabulary allowed_owners or authorize term mutations. Core registration does not add the model to this package's allowlist.

Laravel's `getMorphClass()` determines stored identity. These class declarations do not install host morph maps. Keep resolvers, handlers and authorization independent; use `nvl:doctor --strict --format=json` to review legacy alias mismatches or stored identity drift. See [UPGRADING.md](UPGRADING.md) before changing the host's morph map.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine the read-only checks from loaded NVL package providers. Errors fail the gate, and strict mode also fails warnings. This package's existing Doctor command remains available and uses the same package-owned inspection service.


## Shared infrastructure options

Attachment actions and maintenance command locks use `nvl-taxonomy.locks.store`, then `nvl-core.locks.store`, then `cache.default`. `locks.seconds` and `locks.wait_seconds` still control attachment locking, and command lock durations remain unchanged. Production nodes must share a lock-capable store.

## Next major: isolated schema identities

Use `nvl-taxonomy.tables.<logical-key>` for every table and `nvl-taxonomy.connection` for its database connection. Null connection inherits `nvl-core.connection`, then Laravel's default. Tables are resolved at runtime by the package table definition helper.

| Logical key | New default | Previous name |
| --- | --- | --- |
| `terms` | `nvl_taxonomy_terms` | `terms` |
| `i18n` | `nvl_taxonomy_i18n` | `terms_i18n` |
| `termables` | `nvl_taxonomy_termables` | `termables` |
| `tenant_adoption_copies` | `nvl_taxonomy_tenant_adoption_copies` | `term_tenant_adoption_copies` |

Migration filenames contain `nvl_taxonomy_`. Existing installations must complete the upgrade in `UPGRADING.md` before running new migrations. A pending creator rejects an existing target before that owned migration runs; use `nvl:schema:preflight` for an explicit whole-batch check; legacy storage with old history needs an ownership decision.

Owned cache and lock identities follow `nvl:<package>:<purpose>:…`. Attachment locks use `nvl:taxonomy:attachments:` instead of the generic `attachments:` key. Rebuild and prune locks use `nvl:taxonomy:rebuild:` and `nvl:taxonomy:prune:` outside tenant identities. Existing generic host locks are never acquired or removed. See [UPGRADING](UPGRADING.md) for coordinated worker and lock lease cutover.

## Canonical configuration ownership

Use `nvl-taxonomy` settings in `config/nvl-taxonomy.php` and canonical package environment names. Old generic roots are foreign unless an upgrading NVL host explicitly selects them in Core's default-off compatibility. Canonical false/null/empty values win; no old roots are populated or written back. Keep logical package/resource IDs unchanged. Review [Core's rename inventory and cache/worker cutover](https://github.com/nvl-laravel-suite/core/blob/main/UPGRADING.md#major-5-canonical-configuration-and-environment).

## Testing your app

Inject the supported contract rather than constructing its concrete Action or querying package tables. Replace `Nvl\Taxonomy\Contracts\TaxonomyTreeContract` in Laravel's native container for a host-workflow test:

```php
use Nvl\Taxonomy\Contracts\TaxonomyTreeContract;

$double = Mockery::mock(TaxonomyTreeContract::class);
$this->app->instance(TaxonomyTreeContract::class, $double);
// Configure the exact for arguments and documented return value for your host case.
```

The package's conditional native binding preserves host substitutions. Production uses the real contract; test doubles do not prove its storage/authorization behavior.

A detached fixture for a returned identity/data handle is:

```php
use Nvl\Taxonomy\Models\Term;
$fixture = Term::factory()->withoutParents()->make();
```

Ordinary `make()` may persist declared package parents. `withoutParents()->make()` disables parent expansion/admission for detached fixtures; use explicit persisted parents/owners and matching effective connections for a real `create()`. Factories do not authorize workflows, call Stripe, create backing Media objects or publish Template artifacts. Enabled tenancy requires explicit admitted persisted tenants/parents. Your host test installation supplies Faker; no test runner is a runtime package dependency.

Use Laravel `Event::fake()`, `Queue::fake()`, `Mail::fake()` or `Storage::fake()` only for the effects the host test intends to isolate. Use real commits/listeners for timing proof. Add the optional Core consumer boundary rules to host PHPStan:

```neon
includes:
    - vendor/nvl/core/support/consumer-audit.neon
parameters:
    nvlConsumer:
        testPaths: [tests]
        tableNames: []
        exceptions: []
```

Rules read installed public metadata without suite boot. They flag internal symbols, package model queries/writes, capability relations and owned tables; they cannot prove dynamic code or runtime authorization. Exact exceptions require `file`, `identifier`, `symbol`, and a documented `reason`. The published 5.x family is verified through the local Dagger release gate on PHP 8.4/Laravel 13, including owning suites, MySQL/PostgreSQL persistence contracts and sealed Tenancy consumers. Fresh public Composer installation, discovery and configuration/route caching are verified. PHP 8.5, Laravel 12, MariaDB and the full independent archive matrix require separate evidence. See the [verification and release policy](https://github.com/nvl-laravel-suite/laravel-suite#verification-and-releases).

### Shipped factory states

These runtime builders keep Laravel's native Factory API. The listed methods name explicit supported parent/owner/lifecycle states; follow each factory's native admission requirements. Detached examples above do not assert persistence validity.

| Factory | Explicit states |
| --- | --- |
| [`TermFactory`](database/factories/TermFactory.php) | `category()`, `tag()` |
| [`TermTranslationFactory`](database/factories/TermTranslationFactory.php) | `forTerm(Term $parent)` |
| [`TermableFactory`](database/factories/TermableFactory.php) | `forTerm(Term $parent)`, `forOwner(Model $owner)` |

## Error codes and events

All recognized package failures implement `Nvl\Support\Contracts\PackageException`; only `RespondableException` opts into safe response metadata. Keep native PHP programmer errors and Laravel/SDK exceptions distinct. The optional `PackageExceptionRenderer` is registered by the host in `withExceptions`; it leaves unrelated, marker-only and non-JSON handling to the host. Its JSON envelope is `{message:string, code:string, context:object}`. Request locale is host-owned; diagnostics/previous exceptions are not public copy. Event schemas and source connections are documented in [events](docs/events.md).

The table lists enum discriminators, including any successful codes retained for compatibility. A code is not itself an HTTP status; the throwing exception's `suggestedStatus()` is authoritative, especially legacy/custom constructors. Empty context renders as `{}`; only documented JSON-safe context is presented.

| Code | Suggested status | Public context | Translation key |
| --- | --- | --- | --- |
| `invalid_term_operation` | 422 | Declared safe scalar/array map; otherwise `{}` | `nvl-taxonomy::responsecode.invalid_term_operation` |
| `term_not_found` | 404 | Declared safe scalar/array map; otherwise `{}` | `nvl-taxonomy::responsecode.term_not_found` |
| `operation_failed` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-taxonomy::responsecode.operation_failed` |
| `unsafe_term_deletion` | 409 | Declared safe scalar/array map; otherwise `{}` | `nvl-taxonomy::responsecode.unsafe_term_deletion` |
| `flat_vocabulary` | 422 | Declared safe scalar/array map; otherwise `{}` | `nvl-taxonomy::responsecode.flat_vocabulary` |
| `ambiguous_term_reference` | 422 | Declared safe scalar/array map; otherwise `{}` | `nvl-taxonomy::responsecode.ambiguous_term_reference` |
| `closed_vocabulary` | 422 | Declared safe scalar/array map; otherwise `{}` | `nvl-taxonomy::responsecode.closed_vocabulary` |
| `circular_hierarchy` | 409 | Declared safe scalar/array map; otherwise `{}` | `nvl-taxonomy::responsecode.circular_hierarchy` |
| `stale_term_version` | 409 | Declared safe scalar/array map; otherwise `{}` | `nvl-taxonomy::responsecode.stale_term_version` |
| `unknown_taxonomy` | 404 | Declared safe scalar/array map; otherwise `{}` | `nvl-taxonomy::responsecode.unknown_taxonomy` |
| `invalid_parent` | 422 | Declared safe scalar/array map; otherwise `{}` | `nvl-taxonomy::responsecode.invalid_parent` |
| `batch_read_unavailable` | 500 | Declared safe scalar/array map; otherwise `{}` | `nvl-taxonomy::responsecode.batch_read_unavailable` |
| `maximum_depth_exceeded` | 422 | Declared safe scalar/array map; otherwise `{}` | `nvl-taxonomy::responsecode.maximum_depth_exceeded` |
| `duplicate_sibling_slug` | 409 | Declared safe scalar/array map; otherwise `{}` | `nvl-taxonomy::responsecode.duplicate_sibling_slug` |


## License

Released under the [MIT License](LICENSE).

Owner capability relations `metafields()`, `comments()`, `nvlMediaAssociations()` and `termables()` are enforced statically, not at runtime. They remain ordinary Eloquent relations for package internals. Enable `vendor/nvl/core/support/consumer-audit.neon` in the host PHPStan configuration, and use public workflow contracts and batch readers in consumer code. The static rules do not replace runtime authorization.
