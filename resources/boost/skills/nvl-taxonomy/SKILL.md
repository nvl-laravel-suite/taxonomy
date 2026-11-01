---
name: nvl-taxonomy
description: Use when integrating nvl/taxonomy registered vocabularies, localized hierarchical terms, owner assignments, moves/merges/deletion, bounded tree/batch reads or owner query filters.
---

# NVL Taxonomy

Use the native PHP 8.4 / Laravel 13 services and native Term models. The host
owns HTTP, application Actions/DTOs, presentation and frontend types.

## Reference routing

- [Installation](references/installation.md): dependencies, configuration, migrations and publication.
- [Native API](references/api.md): exact public service, model, registry and policy declarations.
- [Usage](references/usage.md): vocabulary/owner setup, term mutations, assignment and SQL visibility recipes.

## Select the owning service

| Need | Service |
| --- | --- |
| Create/update/move/merge/delete, validate merge, rebuild positions | `TaxonomyTerms` |
| Append, detach or replace ordered owner assignments | `TaxonomyAssignments` |
| Resolve references, read a term/tree, bounded owner batches | `TaxonomyReader` |
| Declare vocabulary semantics | `TaxonomyRegistry` |
| Declare host owner capability | `TaxonomyOwnerRegistry` |

Use complete mutations; internal TermWriter/attachment helpers and direct table
writes do not supply equivalent policy, revision or transaction guarantees.

## Actors, vocabulary and canonical identity

Every native call supplies a persisted Eloquent Authenticatable or explicit
`SystemActor::Background`. Both go through `taxonomy.<ability>` Gates. Null is
not a maintenance principal. Native policy reloads actor facts and retains host
scopes. Gates receive locked canonical terms/vocabulary and related destination
where relevant; create receives the vocabulary name.

Register vocabulary rules and owner aliases before use. Capability aliases do not
change Laravel morph maps. Assignments require registered owners on Taxonomy's
connection so owner locks and term writes share the same transaction.
Bind SQL visibility for private terms/owners; never infer it from an ambient guard.

## Structural and localized input

- Create requires `taxonomy`, canonical `slug` and locale-keyed `translations`.
- Structural native keys use `parent_id` and `meta`, not generic camelCase wrappers.
- Update requires integer `expected_revision`; parent changes use `move`.
- Omitted update translations retain locales; explicit Replace needs a locale map.
- Localized rows contain name/description; slug stays locale independent.
- Null position is invalid; nullable `meta` clears metadata.
- Delete, move and merge take their declared separate revision arguments.
- Restrict is the default deletion strategy; cascading/reparenting is explicit.

Missing references are created only as roots in registered open vocabularies and
require `taxonomy.create`. UUIDs identify existing terms; ambiguous slugs fail.
Preserve normalized-reference and payload bounds rather than silently truncating.

## Assignments and reads

Assignment sync is ordered and all-or-nothing under the owner lock. Tree reads
return bounded native collections. Owner batches admit at most 100 owners and 20
vocabularies and return an object map plus separate exact order, including empty lists.
Keep `pivot_position`, translations and native owner predicates in projections.

`TaxonomyCatalogAuthorization` gates vocabulary reads; `TaxonomyBatchAuthorization`
adds query-free loaded-fact admission and SQL predicates for private data. The
registered defaults preserve capabilities/model scopes, not custom private policy.
Host `withAnyTerms`/all/without and category filters take an explicit canonical actor.

## Models, atomicity and checks

Select `nvl-taxonomy.models.term` before provider boot with a native Term subclass.
Preserve UUID, casts, taxonomy/query scopes, tree/persistence hooks, ownership and
package-configured storage. Added columns are host migrations; fixed translations
and assignment pivots have no replacement selector.

Keep native Ownership declaration/adoption, tree/assignment locking, revision
conflicts, source transactions and outer-commit events. Test cycles/depth,
foreign owner/vocabulary, merge conflicts, deletion strategy, locale modes,
assignment rollback and bounded SQL visibility. Use [usage](references/usage.md)
for supported payloads instead of rebuilding application workflow classes.
