# Changelog

All notable changes to `nvl/taxonomy` are documented here.

## [Unreleased]

## [2.0.0] - 2026-08-29

### Changed

- Established Taxonomy Actions, tree/resolver services, and owner traits as the
  2.0 consumer boundary; direct consumer Term queries and relation aggregates
  now fail Suite audit.

## [1.0.7] - 2026-08-22

### Changed

- Aligned the documented runtime requirement with the PHP 8.4+ package
  baseline.

## [1.0.5] - 2026-08-12

### Changed

- Released unchanged under the suite's shared version.

## [1.0.2] - 2026-08-12

- Added consumer contract coverage for owner queries, maintenance commands, registry validation, schema diagnostics, and distribution hygiene.
- Consolidated hierarchy uniqueness and optimistic-concurrency columns into the clean-install term migration.
- Standardized the localized-term table and configuration key as `terms_i18n`.
- Replaced unrestricted attachment-row mass assignment with an explicit field allowlist.
- Added stable owner morph aliases and UUID-backed attachment pivots.
- Made every mutation transaction use the configured storage connection with deadlock retries.
- Added typed delete strategies and change operations, mandatory optimistic revisions, subtree-aware depth checks, and ambiguity failures for hierarchical slugs.
- Enforced exclusive vocabularies, metadata depth, canonical slugs, and pre-resolution bulk limits.
- Isolated tree rebuilds by vocabulary, protected closed vocabularies from default pruning, and expanded doctor diagnostics.
- Hardened open-term races with savepoint-scoped unique recovery and locked stale-reference checks.
- Consolidated multi-term hierarchy validation into one locked snapshot and isolated merge dry-run validation.
- Required exact concrete owner aliases, rechecked owner existence around writes, and made owner cleanup idempotent before and after deletion.
- Reserved UUID-shaped slugs, bounded translated descriptions, and made doctor and prune maintenance paths resilient to malformed or changing state.

## [1.0.0] - 2026-08-08

- Added registered vocabularies and owners, UUID terms, nullable hierarchy, and typed metadata.
- Added dedicated translated name and description rows with deterministic fallback.
- Added create, update, move, attach, detach, sync, merge, prune, and delete Actions.
- Added cycle, depth, uniqueness, deletion, concurrency, and query-count protections.
