# Upgrading NVL Taxonomy

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
