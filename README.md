# NVL Taxonomy — API and usage

[← NVL Laravel Suite](../../../README.md)

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/laravel-suite:^2.0` |
| Module identifier | `nvl/taxonomy` |
| PHP namespace | `Nvl\Taxonomy` |
| Service provider | `Nvl\Taxonomy\Providers\TaxonomyServiceProvider` |
| Configuration | `config/taxonomy.php` |

## Purpose

`nvl/taxonomy` provides reusable translated vocabularies and hierarchical terms for Laravel 13 on PHP 8.4+. It supports categories, tags, ordered trees, typed metadata, polymorphic owner attachment, moves, merges, pruning, and deterministic localized display copy. It is not an arbitrary attribute, facets, or search engine.

The package depends only on `nvl/data` and `nvl/translatable` inside the NVL family.

## Requirements and installation

```bash
composer require nvl/laravel-suite:^2.0
php artisan migrate
```

Laravel auto-discovers `TaxonomyServiceProvider`. Optional publish tags are:

```bash
php artisan vendor:publish --tag=taxonomy-config
php artisan vendor:publish --tag=taxonomy-migrations
php artisan vendor:publish --tag=taxonomy-skills
```

Clean-install migrations use UUID term identifiers, nullable UUID parent identifiers, dedicated term translations, and string-compatible owner identifiers. Set `taxonomy.migrations.enabled=false` during controlled adoption of existing tables.

Choose exactly one migration owner. For automatic vendor loading, leave
`taxonomy.migrations.enabled=true` and do not publish `taxonomy-migrations`.
For host-owned migrations, publish `taxonomy-migrations`, set
`taxonomy.migrations.enabled=false` before the first migration, and maintain
the copied files as application migrations. Never run both sources; Laravel
retimestamps published migrations.

## Register vocabularies and owners

Declare stable vocabulary rules in `config/taxonomy.php`:

```php
'taxonomies' => [
    'topics' => [
        'model' => \Nvl\Taxonomy\Models\Term::class,
        'hierarchical' => true,
        'exclusive' => false,
        'open' => true,
        'max_depth' => 5,
        'sort' => 'position',
        'allowed_owners' => ['articles'],
        'metadata_rules' => [
            'color' => ['nullable', 'string', 'max:20'],
        ],
    ],
],
'owners' => [
    'articles' => Article::class,
],
```

`TaxonomyRegistry` and `TaxonomyOwnerRegistry` reject invalid and duplicate registrations. Owner aliases are installed in Laravel's morph map and persisted in `termable_type`, so refactoring a PHP namespace does not corrupt attachment identity. Open vocabularies allow term creation through authorized application flows; closed vocabularies require an existing registered term.

Register every concrete owner class that can receive terms. A base-class alias is not inherited by subclasses because Laravel's morph map identifies concrete classes exactly.

## Create a translated term

```php
use Nvl\Taxonomy\Actions\CreateTermAction;
use Nvl\Taxonomy\Data\MutateTermPayload;

$term = app(CreateTermAction::class)->execute(new MutateTermPayload(
    taxonomy: 'topics',
    slug: 'engineering',
    translations: [
        'en' => ['name' => 'Engineering'],
        'bg' => ['name' => 'Инженерство'],
    ],
    parentId: null,
    position: 10,
    meta: ['color' => 'blue'],
));
```

Taxonomy, parent, slug, position, metadata, and owner attachments are structural. Name and description exist only in `terms_i18n` and resolve through `nvl/translatable`. Slugs are canonical and do not change with locale. UUID-shaped slugs are reserved for unambiguous identifier references.

Updates use `UpdateTermAction` and require `expectedRevision`. Stale writes fail instead of silently overwriting newer changes.

## Hierarchy

Use `MoveTermAction` for reparenting. It rejects:

- parents from another vocabulary;
- a term as its own parent;
- ancestor/descendant cycles;
- hierarchy on a flat vocabulary;
- moves beyond the configured maximum depth;
- duplicate sibling slugs.

Ordered tree and subtree reads must eager-load translations and use the package's deterministic ordering. Do not recurse through lazily loaded child relationships in an API transformer.

Use `TaxonomyTree::for($taxonomy, $locale)` for any registered vocabulary. `Category::tree()` is a convenience wrapper around the same generic service.

## Attach terms

Use `AttachTermsAction`, `DetachTermsAction`, or `SyncTermAttachmentsAction` with a registered owner. Do not write the polymorphic attachment table directly. Vocabulary rules enforce allowed owner aliases and exclusive membership where configured.

Attachment actions serialize each owner/vocabulary set with Laravel atomic locks. Production nodes must use a shared lock-capable cache store.

`MergeTermsAction` moves attachments and eligible children under a connection-correct transaction and requires the expected revisions of both terms. `DeleteTermAction` requires an expected revision and a `DeleteTermStrategy`; it rejects unsafe deletion when attachments or children cannot be handled by that strategy.

## Central translation management

Taxonomy registers its term resource with `TranslationResourceRegistry`. Central gather, coverage, read, sync, and locale deletion therefore use the package field whitelist, query scope, authorization, and version hash.

Generate TypeScript declarations under `Nvl.Taxonomy.*`:

```bash
php artisan nvl:data:types:generate
php artisan nvl:data:types:check
```

## Configuration

Important groups are:

- `owners`: stable aliases to Eloquent model classes;
- `taxonomies`: hierarchy, openness, exclusivity, depth, sorting, owner allowlist, and metadata validation;
- `table_names` and `storage.connection`;
- `migrations.enabled`;
- `limits.metadata_bytes`, `metadata_depth`, `description_chars`, and `bulk_terms`;
- `transactions.attempts` for deadlock retries;
- `locks.seconds` and `locks.wait_seconds` for attachment-set serialization;
- `slugs.generator` and `slugs.locale`.

Metadata is bounded and validated; it is not an arbitrary query language.

## Commands

```bash
php artisan nvl:taxonomy:doctor --strict --format=json
php artisan nvl:taxonomy:rebuild --dry-run
php artisan nvl:taxonomy:merge --help
php artisan nvl:taxonomy:prune --dry-run
php artisan nvl:taxonomy:prune category --include-closed --force
```

Doctor verifies required columns and unique indexes plus registry, connection, parent, attachment, translation, cycle, and depth invariants. Dry-run modes do not mutate state. Pruning protects closed vocabularies unless `--include-closed` is supplied explicitly.

## Database and adoption

The schema indexes vocabulary/parent/slug, tree order, owner/type, term attachment, and locale lookups. Package migrations honor configured table names and connection where supported.

Attachment Actions and owner-to-term lazy/eager relations support a dedicated taxonomy connection. Inverse `Term::entries()` joins and owner `with*Terms` / `inCategory` scopes require the owner and taxonomy connections to address the same physical database because Eloquent cannot execute a cross-database relationship subquery portably.

For an existing schema, disable automatic migrations and run the doctor. Convert root sentinels to `null`, backfill dedicated translation rows, and resolve identifier differences in an application-owned reversible bridge. A table-name match is not schema compatibility.

## Authorization, caching, and failures

This package ships no management routes. Applications authorize Action calls and any API built over them. Registry aliases and vocabulary rules are allowlists, not authorization by themselves.

`TermChanged` mutation events implement `ShouldDispatchAfterCommit`. Unknown vocabularies, invalid metadata, stale revisions, ambiguous slugs, hierarchy violations, and unsafe deletes are distinct failures.

## Verification

The package tests cover UUID identifiers, stable morph aliases, translation fallback, slug stability, tree order, cycles, subtree depth, moves, merges, attachments, exclusivity, deletion policies, maintenance safety, and configured-connection behavior. CI runs the package on its supported PHP/Laravel and database matrix.

See [UPGRADING.md](UPGRADING.md), [SECURITY.md](SECURITY.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [CHANGELOG.md](CHANGELOG.md).

## License

Released under the [MIT License](LICENSE).
