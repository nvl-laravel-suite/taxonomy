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

<!-- tenancy-program-p2 -->
Configurable-tenancy implementation and adoption documentation are present. The final consolidated verification matrix is pending; do not treat this package as release-ready until that gate passes.
