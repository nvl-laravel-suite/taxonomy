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

1. Set `taxonomy.migrations.enabled=false` for an existing schema.
2. Run `php artisan nvl:taxonomy:doctor --strict --format=json`.
3. Backfill UUIDs or preserve compatible identifiers in an application-owned bridge.
4. Convert root sentinel values to `null`.
5. Backfill translation rows before removing old JSON or base-column reads.
6. Register vocabulary and owner aliases before reading or writing attachments. Aliases become Laravel morph-map identifiers and must remain stable across model namespace changes.
7. Validate trees, attachments, row counts, and rollback before enabling maintenance commands.

Register aliases for concrete owner classes rather than relying on inheritance, and rename any legacy UUID-shaped slugs because UUID syntax is reserved for term identifiers.

Do not edit deployed package migrations or dual-write legacy columns in v1.

## Shared owner registry compatibility

Move model identity declarations to `nvl-core.owners` and reference the alias from `taxonomy` capability configuration as described in the [README](README.md#shared-owner-identity). Preserve package contracts, resolvers/handlers, visibility scopes, and mutation authorization. Core does not grant package capabilities. Conflicting aliases or multiple canonical aliases for one model fail before use.

Legacy class inputs remain accepted for one major cycle and are reported through Core diagnostics. Existing Content, Taxonomy, and Metafields mappings keep their established aliases. Legacy SEO, Media, Comments, Templates, Pages, and Translatable class-backed behavior does not automatically create a new morph alias. Existing host morph mappings are respected.

Adding a canonical Core alias changes Laravel's write-time morph type for that model. Before adding it to an existing class-backed deployment, explicitly convert the known package-owned morph columns and reconcile every other affected host relationship. Keep unrelated rows and host-owned morph tables unchanged. This release performs no automatic owner-data conversion and does not ship `nvl:owners:upgrade`. Preserve existing aliases when no conversion is required, rebuild configuration caches, and restart workers after the cutover.

## Shared Doctor integration

The loaded package provider now contributes its existing inspection checks to Core's `nvl:doctor --strict --format=json`. The package command remains available. The shared gate fails errors and, in strict mode, warnings; no data upgrade is required for diagnostics.


## Infrastructure option inheritance

Configure a shared `locks.store` in Taxonomy or Core instead of relying on an unrelated application cache. Attachment actions and maintenance commands now consume this setting; preserve attachment lifetime/wait settings. Null inherits Core and the application default, while empty strings fail. Rebuild cached configuration after changing stores.

## Next major: isolated schema identities

This is a breaking schema identity change. Back up storage and migration history, pause writes/workers, install this code with automatic package migrations disabled, and select one owner for migrations (vendor or published).

```sh
php artisan nvl:doctor --strict --format=json
php artisan nvl:schema:upgrade --package=taxonomy --claim-legacy --dry-run --format=json
php artisan nvl:schema:upgrade --package=taxonomy --claim-legacy --format=json
```

The command validates released columns and relational keys plus creating migration history, renames owned legacy tables to the effective `tables.*` targets and rewrites exact package migration identities while retaining batches and unrelated host records. It refuses foreign/incomplete shapes and conflicting targets. Explicit old table mappings retain those names; remove them when choosing new defaults. A second run is empty.

Unmodified published files, including changed timestamps, map by verified checksum to the exact vendor migration identity and current package migration implementation. Modified host copies remain host-owned. Disable vendor loading when retaining a published owner; duplicate ownership fails before migration. No migration files or stored morph types are rewritten.

DDL transactions are driver dependent and per connection. Inspect dry-run warnings for MySQL/MariaDB or split storage; after a failure, inspect completed steps before resuming. Schema-qualified rename targets require an explicit host schema move first. Re-enable your selected migration owner, migrate remaining package changes and rerun Doctor before resuming writes. See the suite upgrade guide for shared owner/locale inputs, Core option defaults and one-major deprecation rules.
