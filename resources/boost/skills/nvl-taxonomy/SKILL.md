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

## Attach and query

- Use `AttachTermsAction`, `DetachTermsAction`, or `SyncTermAttachmentsAction`.
- Never mutate the polymorphic attachment table directly.
- Register every concrete owner class with a stable alias before models boot; attachment rows persist morph aliases and UUID row keys.
- Use a shared lock-capable cache store when attachment mutations can run on multiple nodes.
- Use term UUIDs when a hierarchical slug is ambiguous.
- Reserve UUID-shaped strings for term identifiers; do not use them as canonical slugs.
- Reject cycles, depth overflow, cross-vocabulary parents, invalid metadata, duplicate sibling slugs, and unsafe deletes.
- Load generic ordered trees through `TaxonomyTree`.

## Operate and verify

- Run `nvl:taxonomy:doctor --strict --format=json`.
- Preview maintenance with `nvl:taxonomy:rebuild`, `nvl:taxonomy:merge`, and `nvl:taxonomy:prune` dry-run options.
- Default pruning to open vocabularies; require `--include-closed` before removing canonical closed-vocabulary terms.
- Test UUID identifiers, stable aliases, locale fallback, subtree moves, cycles, merges, exclusive attachments, delete policies, configured connections, and legacy adoption.
