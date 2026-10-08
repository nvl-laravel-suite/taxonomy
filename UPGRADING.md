# Upgrading NVL Taxonomy

## Consumer contracts, committed events and runtime policy (5.x)

Prefer focused public interfaces in constructor injection; native implementations remain container defaults and host prebindings win. Returned models are documented identity/data handles: use package contracts for reads/writes and capability-specific batch readers instead of direct package queries. Enable the shipped Core PHPStan include in your host; do not invoke the suite workbench static audit command in a consumer.

Events now carry immutable schemaVersion=1 and scalar/DTO snapshots. Replace model-bearing event fields with the IDs listed in [events](docs/events.md); load only through an authorized public reader when needed. Only six declared legacy `*Event` names are retained as PHP aliases for major 5, removal no earlier than major 6. Migrate exact imports/listeners/fakes to canonical names, replace suffix wildcard patterns explicitly, drain old queued payloads, rebuild event caches and restart workers. Framework Verified/PasswordReset remain native classes. Source-connection callbacks are process-local after-commit publication, not a durable outbox or exactly-once delivery.

Package failures have a marker and optional response metadata. Opt into Core's JSON renderer deliberately; preserve existing host handlers and request-locale selection. Missing required host adapters produce `binding_required`/500; genuine configured authorization denial retains native handling. See the README error table and required-bindings section where applicable.

Factories ship in runtime package mappings for host tests. Ordinary make may persist parents; withoutParents()->make creates detached fixtures. Supply persisted native owners/parents and active tenants explicitly, retain source revisions, and never treat a factory row as a real storage/provider/workflow effect. Core's optional installer publishes common config without enabling features; strict Doctor and explicit deployment cache/worker steps belong in the host release process. The PHP 8.4/Laravel 13 local Dagger release gate and fresh public Composer installation passed for 5.0.0. Additional compatibility legs need separate evidence; hosts must verify their own adoption.


## Bounded owner display reads

Replace per-owner term and translated-name loops with the container-bound `Nvl\Taxonomy\Contracts\ListOwnerTaxonomyTermsContract`. Pass persisted registered models, requested vocabulary names, and an optional locale. The DTO object maps preserve native morph identities, numeric keys, explicit empty vocabulary lists, and first-request order; consumers need no package-model or relationship queries.

Requests accept at most 100 input owners and 20 declared vocabularies. A visible owner/vocabulary set above 100 terms now fails explicitly before term/translation transfer. Review callers with dense attachment sets rather than silently truncating them. The default batch policy preserves existing explicit owner/vocabulary capabilities; private deployments must bind a query-free SQL implementation of `Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization` before using the reader or audited host scopes.

Host scopes retain their empty-reference semantics and accept an optional compatible policy. They require canonical builder storage, enforce live owner and tenant guards around caller OR clauses, and use registered visible term models for bounded category traversal. A removed host soft-delete scope does not opt deleted records into these package filters. UUID and slug references remain independent, including two references to one term. Read the measured budgets and row ceilings in [README.md](README.md#batched-owner-reads). No schema or stored-identity conversion accompanies these read APIs.

Review registered owners whose table basename matches the requested vocabulary's term-model table or the attachment table, including schema-qualified names and case variants. Nonempty host term filters and category filters now reject that ambiguous correlation with `Nvl\Taxonomy\Exceptions\TaxonomyBatchReadException` before SQL. Use the bounded owner reader for display payloads; empty host term filters retain their existing semantics.

Core's disabled boundary admits registered Term subclasses on canonical storage, so these reads and filters work without loading the optional Tenancy provider. The dedicated no-provider regression checks both built-in vocabularies. This does not activate tenancy or reopen already-adopted storage.

## Tenant ownership adoption

Taxonomy tenancy is opt-in through `nvl/tenancy` and is inert while
`tenancy.enabled=false`. Before enabling it, register every concrete owner as a
Foundation tenant resource, configure tenant-enabled vocabularies with the
canonical `Term` model, and run the reviewed adoption coordinator for the
`taxonomy` package. The adapter expands nullable ownership, copies reviewed
multi-tenant tree graphs, verifies canonical owners and locale rows, then
activates composite constraints. Do not run the constrain migration directly.

Enabled maintenance commands require `--tenant=<uuid>`. Existing vocabulary
names remain global code configuration; terms and attachments never encode a
tenant in the vocabulary alias.

Take a pre-cutover backup. Source/schema repairs consistent with the prepared
mapping may resume the same run; changed tenant assignments, split graphs, or
destination UUIDs require restore and a new reviewed prepare. Do not describe
dropping ownership columns as rollback after tenant-local duplicate slugs exist.
Run cleanup as one bounded package-owned tenant graph and retain its adoption
ledger until recovery policy permits removal.

## Upgrading to 1.0

Version 1.0 uses UUID term and attachment-row identifiers, nullable root parents, canonical nonlocalized slugs, stable owner morph aliases, composite taxonomy foreign keys, and dedicated translation rows.

1. Set `nvl-taxonomy.migrations.enabled=false` for an existing schema.
2. Run `php artisan nvl:taxonomy:doctor --strict --format=json`.
3. Backfill UUIDs or preserve compatible identifiers in an application-owned bridge.
4. Convert root sentinel values to `null`.
5. Backfill translation rows before removing old JSON or base-column reads.
6. Register vocabulary and owner aliases before reading or writing attachments. Aliases become Laravel morph-map identifiers and must remain stable across model namespace changes.
7. Validate trees, attachments, row counts, and rollback before enabling maintenance commands.

Register aliases for concrete owner classes rather than relying on inheritance, and rename any legacy UUID-shaped slugs because UUID syntax is reserved for term identifiers.

Do not edit deployed package migrations or dual-write legacy columns in v1.

## Shared owner registry compatibility

Declare owner classes in `nvl-core.owners`, for example `'owners' => [Article::class]`, and reference the same model class from each package capability. Laravel's `getMorphClass()` is the stored owner identity: it returns the host-authored morph alias or the FQCN when no map exists. Core declarations and package allowlists do not add or enforce a host morph map and do not grant authorization.

Legacy alias references remain read compatibility during major 5 and are removed in major 6. A legacy configured alias must agree with the model's current `getMorphClass()`; mismatches are diagnostics and require a host decision. Doctor can inspect declared package owner columns for stored-versus-current identities without rewriting them. If the host introduces or changes its morph map, review and convert only the affected stored columns and reconcile host relationships before cutover. No automatic owner-data conversion or `nvl:owners:upgrade` is provided. Rebuild configuration caches and restart workers after the coordinated change.

## Shared Doctor integration

The loaded package provider now contributes its existing inspection checks to Core's `nvl:doctor --strict --format=json`. The package command remains available. The shared gate fails errors and, in strict mode, warnings; no data upgrade is required for diagnostics.


## Infrastructure option inheritance

Configure a shared `locks.store` in Taxonomy or Core instead of relying on an unrelated application cache. Attachment actions and maintenance commands now consume this setting; preserve attachment lifetime/wait settings. Null inherits Core and the application default, while empty strings fail. Rebuild cached configuration after changing stores.

## Next major: isolated schema identities

This is a breaking schema identity change. Back up storage and migration history, pause writes/workers, install this code with automatic package migrations disabled, and select one owner for migrations (vendor or published).

```sh
php artisan nvl:doctor --strict --format=json
php artisan nvl:schema:upgrade --package=taxonomy --claim-legacy --migration-owner=vendor --dry-run --format=json
php artisan nvl:schema:upgrade --package=taxonomy --claim-legacy --migration-owner=vendor --format=json
```

The command validates released columns and relational keys plus creating migration history, renames owned legacy tables to the effective `tables.*` targets and rewrites exact package migration identities while retaining batches and unrelated host records. It refuses foreign/incomplete shapes and conflicting targets. Explicit old table mappings retain those names; remove them when choosing new defaults. A second run is empty.

Declare each published path and canonical identity explicitly in `nvl-core.migrations.published`; retimestamped history also needs an exact `legacy` mapping. Use `--migration-owner=vendor` after manually archiving declared copies outside loaded paths, or `--migration-owner=published` after manually replacing executable copies with current migration code and disabling vendor loading. The plan verifies ownership and preserves batches; checksums do not automatically claim files. Modified host copies remain host-owned. No migration files or stored morph types are rewritten.

DDL transactions are driver dependent and per connection. Inspect dry-run warnings for MySQL/MariaDB or split storage; after a failure, inspect completed steps before resuming. Schema-qualified rename targets require an explicit host schema move first. Re-enable your selected migration owner, run `nvl:schema:preflight` with the same selected paths and connection, then migrate remaining package changes and rerun Doctor before resuming writes. See the suite upgrade guide for shared owner/locale inputs, Core option defaults and one-major deprecation rules.

## Major 5 cache and lock identities

Attachment locks use `nvl:taxonomy:attachments:` instead of the generic `attachments:` key. Rebuild and prune locks use `nvl:taxonomy:rebuild:` and `nvl:taxonomy:prune:` outside tenant identities. Existing generic host locks are never acquired or removed.

Drain old mutation workers and maintenance processes, then wait for their outstanding lock leases to end before starting the new major across all nodes. Running old and new lock prefixes concurrently would create independent serialization domains. Restart workers after cutover; preserve host-selected stores and keys, and do not flush a shared cache to remove old NVL entries.

## Tagged consumer PHP boundary

Use source `@api` workflows, extension contracts, and value types for application integration. Direct use of untagged implementations or `@internal` members is unsupported. This classification keeps existing concrete Action signatures and runtime behavior; it does not authorize package model persistence, ad hoc queries, relation traversal, or generic model serialization. Returned models are identity/result handles with only the explicitly declared in-memory read fields described in the README.

The implementation Actions `RebuildTreeAction`, `ResolveTermsAction`, `ValidateTermMergeAction` are explicitly internal. Run `nvl:taxonomy:rebuild` for tree maintenance, read through `ListOwnerTaxonomyTermsAction`, and merge through `MergeTermsAction` or `nvl:taxonomy:merge`; the complete workflows own term resolution and merge validation.

`HasTaxonomies::termables()` and `hasTerm()` are internal storage or lifecycle seams. Migrate direct traversal, eager/lazy loading, and aggregate queries to the package authorized read Actions or explicit C1 host scopes/adapters. Native host queries and opted-in Translatable behavior remain supported.

## Major 5 workflow injection

Replace host constructor dependencies on selected concrete Actions with their focused `Nvl\Taxonomy\Contracts\*Contract` equivalents listed in the README. Existing equivalent workflow contracts are reused. Native concrete constructors, qualifiers, argument defaults, result types, and execution behavior remain compatible. Internal package Action/service chains retain their existing concrete dependencies.

Default workflow registrations use `bindIf`, retaining host interfaces/instances registered before discovery. Register substitutes at the interface key; newly resolved host services receive late replacements. Substituting a workflow does not exercise the native authorization, storage, or lifecycle invariants, which require the owning integration coverage.

Use transient `TaxonomyTreeContract` and `TermResolverContract` for the existing service APIs. Preserve Eloquent Term handles and localized children semantics; the resolver’s `createMissing` default remains true. Private registries, writers, locale and tenant dependencies do not become public extension interfaces.
