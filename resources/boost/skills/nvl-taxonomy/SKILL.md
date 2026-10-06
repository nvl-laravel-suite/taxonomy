---
name: nvl-taxonomy
description: Implement, integrate, test, or review nvl/taxonomy in Laravel 13. Use for registered vocabularies, UUID hierarchical terms, localized names and descriptions, owner attachment, moves, merges, pruning, cycle prevention, tree loading, metadata schemas, or taxonomy diagnostics.
---

# NVL Taxonomy

Treat vocabulary, parent, slug, order, metadata, and attachments as structural data. Store name and description only in dedicated translation rows.

## Register and mutate

- Register vocabulary rules through `TaxonomyRegistry`.
- Register stable owner aliases through `TaxonomyOwnerRegistry`.
- Use `CreateTermAction` and `UpdateTermAction` with `MutateTermPayload`.
- Use `MoveTermAction`, `MergeTermsAction`, and `DeleteTermAction` for hierarchy changes.
- Require expected revisions for updates, moves, deletes, and both sides of merges.
- Pass a `DeleteTermStrategy` explicitly when a delete must handle attachments or children.
- Keep canonical slugs locale-independent.
- Terms declare `TranslationMutationPolicy::DomainActionOnly`. Central
  translation gathering, coverage, and reads are available; generic central
  sync and locale deletion reject term mutations. Write `translations` through
  `CreateTermAction` or `UpdateTermAction` with `MutateTermPayload`. To remove
  locales, pass `mode: TranslationSyncMode::Replace` to
  `UpdateTermAction::execute()` with the retained locale map and the expected
  term revision in the payload.
- Consumer applications authorize domain Actions before accepting user input.

## Attach and query

- Use `AttachTermsAction`, `DetachTermsAction`, or `SyncTermAttachmentsAction`.
- Never mutate the polymorphic attachment table directly.
- Register every concrete owner class with a stable alias before models boot; attachment rows persist morph aliases and UUID row keys.
- In tenant mode, also register every owner with `TenantResourceRegistry`, use
  canonical `Term` vocabulary models, and adopt the `taxonomy` package before
  traffic. Vocabulary aliases stay global; persisted trees are always local.
- Never trust passed or loaded terms and owners. Reload them through the active
  boundary, and preserve tenant predicates in OR and NOT EXISTS scopes.
- Use a shared lock-capable cache store when attachment mutations can run on multiple nodes.
- Use term UUIDs when a hierarchical slug is ambiguous.
- Reserve UUID-shaped strings for term identifiers; do not use them as canonical slugs.
- Reject cycles, depth overflow, cross-vocabulary parents, invalid metadata, duplicate sibling slugs, and unsafe deletes.
- Load generic ordered trees through `TaxonomyTree`.

## Operate and verify

- Keep tenancy opt-in, vocabulary declarations global, and every term,
  translation, hierarchy edge, and owner attachment tenant-local.
- Adopt configured tables and connections through the package adapter; never
  create a generic tenancy pivot or infer an unknown owner partition.
- Resume only source/schema repairs consistent with the immutable reviewed
  mapping. Changed split/destination mappings require restore/new prepare.

- Run `nvl:taxonomy:doctor --strict --format=json`.
- Preview maintenance with `nvl:taxonomy:rebuild`, `nvl:taxonomy:merge`, and `nvl:taxonomy:prune` dry-run options.
- Supply `--tenant=<uuid>` to every maintenance command when tenancy is enabled.
- Default pruning to open vocabularies; require `--include-closed` before removing canonical closed-vocabulary terms.
- Test UUID identifiers, stable aliases, locale fallback, subtree moves, cycles, merges, exclusive attachments, delete policies, configured connections, and legacy adoption.

## Configurable-tenancy release discipline

- Preserve disabled compatibility and package independence; tenant support never creates an undeclared Auth or Suite dependency.
- Use registered package-owned resources, adoption adapters, Actions, and lifecycle APIs. Never add a generic tenant delete-all path or raw cross-package cleanup.
- Treat mapping/configuration hashes, interruption checkpoints, conservation evidence, worker context, tenant-leading queries, and standalone consumption as release contracts.

## Shared owner identities

- Declare owner class lists in `nvl-core.owners` and reference model classes in `taxonomy` capability configuration. Laravel `getMorphClass()` supplies the host-authored stored identity; declarations do not add global host morph mappings.
- The shared alias must match the Taxonomy owner identity. Registering an identity does not expand vocabulary allowed_owners or authorize term mutations.
- Keep the package allowlist and authorization independent of Core registration. Never authorize a model merely because Core knows it.
- Preserve resolvers, handlers and authorization. Legacy aliases require agreement with native `getMorphClass()` and are removed in major 6; Doctor reports mismatches and stored identity drift without conversion.
- If the host changes its morph map, explicitly reconcile reviewed package-owned columns and affected host relations before cutover. Core and package capability registration never mutate the host morph map or rewrite stored values.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine checks from loaded NVL providers. Retain the package Doctor command for its detailed report; both paths reuse the package-owned inspection service.


## Shared infrastructure options

- Attachment actions and maintenance commands must use `nvl-taxonomy.locks.store`, then Core, then `cache.default`. Preserve attachment lifetime/wait bounds and existing command lock durations; production nodes need a shared lock-capable store.

### Brownfield storage identities

Resolve all package tables through the table helper and canonical `nvl-taxonomy.tables.*`, connections through `nvl-taxonomy.connection` with Core/Laravel inheritance. Defaults use `nvl_taxonomy_*`; migration filenames include that package slug. Never silently adopt a matching table or generic migration filename. Run shared `nvl:doctor --strict --format=json` and the explicit `nvl:schema:upgrade --package=taxonomy --claim-legacy --dry-run --format=json` before upgrading owned legacy storage. Validate the complete plan and choose one migration owner. Preserve host records, constraint names and stored morph values. Deprecated config inputs last one major; canonical options take precedence.

## Cache and lock ownership

Attachment locks use `nvl:taxonomy:attachments:` instead of the generic `attachments:` key. Rebuild and prune locks use `nvl:taxonomy:rebuild:` and `nvl:taxonomy:prune:` outside tenant identities. Existing generic host locks are never acquired or removed.

Drain old mutation workers and maintenance processes, then wait for their outstanding lock leases to end before starting the new major across all nodes. Running old and new lock prefixes concurrently would create independent serialization domains. Restart workers after cutover; preserve host-selected stores and keys, and do not flush a shared cache to remove old NVL entries.

## Canonical configuration ownership

- Read/write `nvl-taxonomy` configuration and publish only canonical `nvl-<package>-<resource>` tags. Keep logical package/tenant resource identifiers unchanged.
- Generic config roots and unprefixed package environment names are foreign by default. For an upgrading NVL host only, select `nvl-core.compatibility.legacy_config` package IDs and `legacy_env` explicitly; both default off. Canonical presence wins, including false/null/empty values. Legacy inputs are read without writing back and are removed in major 6.
- Use canonical `NVL_<PACKAGE>_*` variables only in config evaluation, then rebuild configuration caches and restart workers after cutover. Shared Laravel environment variables retain their names. Consult Core's versioned `support/resources/global-names.json` for all renames.
- Old global aliases and legacy route families require separate explicit `global_aliases`/`legacy_routes` package selections. Preserve collisions and use Doctor diagnostics; never grant generic permissions automatically or claim signed-link compatibility without the same authorization/signature checks.
