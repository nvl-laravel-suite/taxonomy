# NVL Taxonomy

Native hierarchical vocabularies, translated terms and owner assignments for PHP 8.4 and Laravel 13. The consumer owns HTTP, orchestration Actions, DTOs, resources and frontend types.

Publish `nvl-taxonomy-config` when configuring vocabularies and registered owners. Select one migration source and configure authorization before invoking operations. See [UPGRADING.md](UPGRADING.md) for storage changes and [events](docs/events.md) for publication timing.

The native service PHPDoc is the signature reference: [TaxonomyTerms](src/Services/TaxonomyTerms.php), [TaxonomyAssignments](src/Services/TaxonomyAssignments.php), [TaxonomyReader](src/Services/TaxonomyReader.php). Registry declarations live in [TaxonomyRegistry](src/Support/TaxonomyRegistry.php) and [TaxonomyOwnerRegistry](src/Services/TaxonomyOwnerRegistry.php).

`TaxonomyTree` and `TermResolver` are internal collaborators. Use `TaxonomyReader` for authorized resolution and bounded tree reads.


Call these services with an explicit persisted `Authenticatable` or `SystemActor::Background`. A model actor is reloaded and authorized through the `taxonomy.<ability>` Gate policy. The background actor is an explicit maintenance principal; never turn `null` into it.

Create policies receive the vocabulary name. Update, delete, and move policies receive the locked canonical term, its vocabulary, and the related destination when applicable. Merge policies receive the locked source, vocabulary, and destination; `validateMerge` uses the same authorization. Assignment policies receive the locked canonical owner and vocabulary. Rebuild policies receive the requested vocabulary when supplied. Undefined abilities deny both human and system actors.

`TaxonomyCatalogAuthorization` and every `TaxonomyBatchAuthorization` callback receive the freshly resolved explicit actor as their first argument. The default adapters admit registered capabilities and retain native model scopes; bind SQL visibility policies for private vocabularies and attachments. Policies must not borrow an ambient authenticated user.

## Structural terms

- Register vocabulary rules through `TaxonomyRegistry` and owner capabilities through `TaxonomyOwnerRegistry`.
- Use `TaxonomyTerms::create(array $attributes, $actor)` and `update(Term|string $term, array $attributes, $actor, TranslationSyncMode $mode)` for canonical slugs, parent, position, metadata, and locale keyed `translations`.
- Use `TaxonomyTerms::move`, `merge`, `delete`, `rebuild`, and `validateMerge` for complete tree operations. Update, move, delete, and merge require expected revisions. A move validates cycles, depth, vocabulary, and ownership while holding locks.
- Names and descriptions live only in the `i18n` table (`nvl_taxonomy_i18n` by default); slugs remain canonical and locale independent. Use `TranslationSyncMode::Replace` only for an explicit retained locale map.
- Structural update patches can omit `translations` to preserve every locale. Replacement requires an explicit locale map. An explicit null position is invalid; null metadata clears it.
- `DeleteTermStrategy` determines handling of children and attachments. Restrict is the default.

## Assignments and reads

- Use `TaxonomyAssignments::attach`, `detach`, or `sync` on persisted registered owners; all operations retain the owner lock and transaction. `sync` is all or nothing and preserves supplied order.
- Use `TaxonomyReader::resolve` for ordered references and creation of missing roots only in registered open vocabularies. UUID inputs identify existing terms; ambiguous slugs are rejected.
- Resolution accepts a list bounded by `limits.bulk_terms` distinct normalized references (500 by default). Creating missing roots requires `taxonomy.create`, including when resolving references during assignment; assigning existing terms needs no create permission.
- Use `TaxonomyReader::forOwners` with at most 100 owners and 20 vocabularies. It returns `['owners' => stdClass, 'order' => list]`; each requested owner and vocabulary has an explicit list of preloaded native `Term` models, including empty lists. The attached order is available as `pivot_position`.
- The owner batch projects the term's declared `metadata_rules` paths without extra queries. A full array field declaration includes its children. Declare narrower paths for data with private siblings.
- For tree reads use `TaxonomyReader::tree` with a bound up to 500 terms. The registered vocabulary and native model scopes apply before loading.
- Preserve the correlated owner filter policy (`TaxonomyBatchAuthorization`) and native ownership predicates. Never read a private term or another owner's attachment through a loose OR or unscoped relation.

Host filters also require an explicit actor: `withAnyTerms($taxonomy, $values, $actor, $policy = null)`, `withAllTerms(...)`, `withoutTerms(...)`, and `inCategory($category, $actor, $includeDescendants = true, $policy = null)`. They enforce `taxonomy.read` and pass the canonical actor into SQL policies. Assignment owners must use the same connection as Taxonomy so the owner lock and attachment changes share one transaction.

### Term membership order and reconciliation

Use [TaxonomyMemberOrder](src/Services/TaxonomyMemberOrder.php) to page through a term's attachments
or reorder them by an attachment UUID to position map. Null clears a position.
Unpositioned attachments follow positioned attachments, with ties ordered by
UUID. This order is independent of `pivot_position`. Define
`taxonomy.read-members` and `taxonomy.reorder-members` only for actors
authorized for all members of the canonical admitted term. The service also
uses `taxonomy.read` and vocabulary and term visibility checks.

Bind [TaxonomyMembershipPolicy](src/Contracts/TaxonomyMembershipPolicy.php) and
pass a [TaxonomyMembershipProvider](src/Contracts/TaxonomyMembershipProvider.php)
to `TaxonomyAssignments::reconcile` for host-owned rule evaluation. An
`Apply` proposal replaces the owner vocabulary membership and stamps each
attachment with its source. A `Shadow` proposal records the observation without
creating terms or changing assignments. Both are stored as the latest
`MembershipMatch` per owner, taxonomy and source. A bound policy also reviews
attach, detach, sync, merge, delete and reorder (source `manual`); it must
return `Apply` or the write is rejected. Sync clears the source on every row;
attach clears it on the incoming terms. While a policy is bound, the
relationship helpers (`$model->tags()->attach()`, `sync()`, `detach()`) throw
`InvalidTermOperationException`; write memberships through
`TaxonomyAssignments` instead. Owner deletion removes the owner's matches
together with its attachments.

Manual writes lock the owner and then its terms, while term delete and merge
lock the term and then its owners. On PostgreSQL the two orders can deadlock;
the database aborts one transaction and the package retries it up to
`nvl-taxonomy.transactions.attempts` times. This is an accepted, retried
deadlock, not data loss. The provider's `record` method runs
under the same owner lock and transaction, so use the canonical connection for
durable observations. Reserve remote effects for post-commit processing.
`TermMerged` gives listeners both term IDs after the source transaction commits.

## Ownership and maintenance

- Ownable resources are declared by the configured term root and `TaxonomyResourceRegistrar`. Unscoped hosts remain unscoped. Native adoption uses explicit reviewed mappings; it is not automatic old NVL schema conversion.
- Use the doctor and maintenance commands for readiness, merge, prune, and position rebuild. Owned operations require an established native owner context.
- Verify cycle and cross vocabulary/owner rejection, merge conflicts, localized copy, revision conflicts, all or nothing assignment sync, deterministic owner batches, empty results, and ownership concurrency.

System actors still require explicit policy permission; their identity is not a bypass. Preserve canonical owner identity, source transactions and native ownership admission when composing host workflows.

Released under the [MIT License](LICENSE).

Shared [service contracts, model extensions and composition](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/consumer-api.md) apply alongside the package-specific rules above.


Attachment `termable_type` stores the owner's `getMorphClass()`, not its registry alias. Keep it stable; changing it requires an explicit migration of existing attachments. Native assignment sync preserves unchanged attachment IDs and creation timestamps and changes only added, removed or repositioned rows. Term deletion defaults to Restrict; direct database deletion still follows the schema's cascading attachment and translation foreign keys.

Ordinary create/update validation locks only the prospective ancestor chain, and flat root validation does not scan the vocabulary. Descendant reads are limited to the affected subtree. Moves and reparenting retain a locked vocabulary snapshot to serialize cycle and depth validation; budget those operations for the vocabulary size. Attachment mutations also lock the canonical owner row; use a shared cache lock store when coordinating multiple processes.

Cache locks coordinate application workers; canonical owner/vocabulary database row locks remain the integrity boundary on the shared transaction source. A process-local cache lock alone is not distributed coordination. Merge removes source attachments already present at the destination and preserves the distinct canonical positions of the others; it does not introduce duplicate owner/vocabulary positions.
