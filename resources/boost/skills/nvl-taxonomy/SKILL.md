---
name: nvl-taxonomy
description: Implement, integrate, test, or review nvl/taxonomy in Laravel 13. Use for registered vocabularies, hierarchical terms, localized names, owner assignments, moves, merges, tree reads, and diagnostics.
---

# NVL Taxonomy

Use the native Light services with an explicit persisted `Authenticatable` or `SystemActor::Background`. A model actor is reloaded and authorized through the `taxonomy.<ability>` Gate policy. The background actor is an explicit maintenance principal; never turn `null` into it.

## Structural terms

- Register vocabulary rules through `TaxonomyRegistry` and owner capabilities through `TaxonomyOwnerRegistry`.
- Use `TaxonomyTerms::create(array $attributes, $actor)` and `update(Term|string $term, array $attributes, $actor, TranslationSyncMode $mode)` for canonical slugs, parent, position, metadata, and locale keyed `translations`.
- Use `TaxonomyTerms::move`, `merge`, `delete`, `rebuild`, and `validateMerge` for complete tree operations. Update, move, delete, and merge require expected revisions. A move validates cycles, depth, vocabulary, and ownership while holding locks.
- Names and descriptions live only in `term_translations`; slugs remain canonical and locale independent. Use `TranslationSyncMode::Replace` only for an explicit retained locale map.
- `DeleteTermStrategy` determines handling of children and attachments. Restrict is the default.

## Assignments and reads

- Use `TaxonomyAssignments::attach`, `detach`, or `sync` on persisted registered owners; all operations retain the owner lock and transaction. `sync` is all or nothing and preserves supplied order.
- Use `TaxonomyReader::resolve` for ordered references and creation of missing roots only in registered open vocabularies. UUID inputs identify existing terms; ambiguous slugs are rejected.
- Use `TaxonomyReader::forOwners` with at most 100 owners and 20 vocabularies. It returns `['owners' => stdClass, 'order' => list]`; each requested owner and vocabulary has an explicit list of preloaded native `Term` models, including empty lists. The attached order is available as `pivot_position`.
- For tree reads use `TaxonomyReader::tree` with a bound up to 500 terms. The registered vocabulary and native model scopes apply before loading.
- Preserve the correlated owner filter policy (`TaxonomyBatchAuthorization`) and native ownership predicates. Never read a private term or another owner's attachment through a loose OR or unscoped relation.

## Ownership and maintenance

- Ownable resources are declared by the configured term root and `TaxonomyResourceRegistrar`. Unscoped hosts remain unscoped. Native adoption uses explicit reviewed mappings; it is not automatic old NVL schema conversion.
- Use the doctor and maintenance commands for readiness, merge, prune, and position rebuild. Owned operations require an established native owner context.
- Verify cycle and cross vocabulary/owner rejection, merge conflicts, localized copy, revision conflicts, all or nothing assignment sync, deterministic owner batches, empty results, and ownership concurrency.
