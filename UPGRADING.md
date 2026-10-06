# Upgrading NVL Taxonomy

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
