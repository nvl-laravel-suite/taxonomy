# Public API: nvl/taxonomy

Generated from the package public contract. Use this reference to confirm fully qualified names, parameter order, defaults, native array shapes and return types. Read [usage](usage.md) for policy and operation semantics; declarations alone never grant authority.

Search for the relevant fully qualified service or contract and read only its matching symbol sections; this is a lookup catalog, not an instruction to load every declaration. `resolved` annotations expand imported aliases and domain type shapes. Framework inheritance and host-authored extensions remain outside this package contract.

## Symbols

- `Nvl\Taxonomy\Concerns\HasTaxonomies`
- `Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization`
- `Nvl\Taxonomy\Contracts\TaxonomyCatalogAuthorization`
- `Nvl\Taxonomy\Database\Factories\TermFactory`
- `Nvl\Taxonomy\Database\Factories\TermTranslationFactory`
- `Nvl\Taxonomy\Database\Factories\TermableFactory`
- `Nvl\Taxonomy\Enums\DeleteTermStrategy`
- `Nvl\Taxonomy\Enums\TaxonomyResponseCode`
- `Nvl\Taxonomy\Enums\TermChangeOperation`
- `Nvl\Taxonomy\Events\TermChanged`
- `Nvl\Taxonomy\Exceptions\AmbiguousTermReferenceException`
- `Nvl\Taxonomy\Exceptions\CircularHierarchyException`
- `Nvl\Taxonomy\Exceptions\ClosedVocabularyException`
- `Nvl\Taxonomy\Exceptions\DuplicateSiblingSlugException`
- `Nvl\Taxonomy\Exceptions\FlatVocabularyException`
- `Nvl\Taxonomy\Exceptions\InvalidParentException`
- `Nvl\Taxonomy\Exceptions\InvalidTermOperationException`
- `Nvl\Taxonomy\Exceptions\MaximumDepthExceededException`
- `Nvl\Taxonomy\Exceptions\StaleTermVersionException`
- `Nvl\Taxonomy\Exceptions\TaxonomyBatchReadException`
- `Nvl\Taxonomy\Exceptions\TaxonomyException`
- `Nvl\Taxonomy\Exceptions\TermNotFoundException`
- `Nvl\Taxonomy\Exceptions\UnknownTaxonomyException`
- `Nvl\Taxonomy\Exceptions\UnsafeTermDeletionException`
- `Nvl\Taxonomy\Models\Term`
- `Nvl\Taxonomy\Models\TermTranslation`
- `Nvl\Taxonomy\Models\Termable`
- `Nvl\Taxonomy\Services\TaxonomyAssignments`
- `Nvl\Taxonomy\Services\TaxonomyOwnerRegistry`
- `Nvl\Taxonomy\Services\TaxonomyReader`
- `Nvl\Taxonomy\Services\TaxonomyTerms`
- `Nvl\Taxonomy\Services\TermModelRegistry`
- `Nvl\Taxonomy\Support\TaxonomyDefinition`
- `Nvl\Taxonomy\Support\TaxonomyRegistry`

## Nvl\Taxonomy\Concerns\HasTaxonomies

```text
trait Nvl\Taxonomy\Concerns\HasTaxonomies
@mixin Model
resolved @mixin Illuminate\Database\Eloquent\Model
public function scopeInCategory(Illuminate\Database\Eloquent\Builder $query, Nvl\Taxonomy\Models\Term $category, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, bool $includeDescendants=true, ?Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization $policy=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Concerns\HasTaxonomies> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Concerns\HasTaxonomies>
public function scopeWithAllTerms(Illuminate\Database\Eloquent\Builder $query, string $taxonomy, array $values, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization $policy=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Concerns\HasTaxonomies> $query
@param list<(string | int)> $values
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Concerns\HasTaxonomies>
public function scopeWithAnyTerms(Illuminate\Database\Eloquent\Builder $query, string $taxonomy, array $values, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization $policy=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Concerns\HasTaxonomies> $query
@param list<(string | int)> $values
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Concerns\HasTaxonomies>
public function scopeWithoutTerms(Illuminate\Database\Eloquent\Builder $query, string $taxonomy, array $values, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization $policy=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Concerns\HasTaxonomies> $query
@param list<(string | int)> $values
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Concerns\HasTaxonomies>
```

## Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization

```text
interface Nvl\Taxonomy\Contracts\TaxonomyBatchAuthorization
public abstract function authorizeOwners(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Support\Owners\OwnerBatch $owners, array $vocabularies): void
@param list<string> $vocabularies
public abstract function scopeAttachments(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Builder $query, Nvl\Support\Owners\OwnerBatch $owners, array $vocabularies): void
@param Builder<Termable> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Termable> $query
@param list<string> $vocabularies
public abstract function scopeHostTerms(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Builder $query, Illuminate\Database\Eloquent\Model $ownerPrototype, string $vocabulary): void
@param Builder<Term> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term> $query
public abstract function scopeTerms(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Illuminate\Database\Eloquent\Builder $query, array $vocabularies): void
@param Builder<Term> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term> $query
@param list<string> $vocabularies
```

## Nvl\Taxonomy\Contracts\TaxonomyCatalogAuthorization

```text
interface Nvl\Taxonomy\Contracts\TaxonomyCatalogAuthorization
public abstract function authorizeVocabulary(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $taxonomy): void
```

## Nvl\Taxonomy\Database\Factories\TermFactory

```text
final class Nvl\Taxonomy\Database\Factories\TermFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<Term>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Taxonomy\Models\Term>
public function category(): static
public function definition(): array
@return array<model-property<Term>, mixed>
resolved @return array<model-property<Nvl\Taxonomy\Models\Term>, mixed>
public function modelName(): string
public function tag(): static
```

## Nvl\Taxonomy\Database\Factories\TermTranslationFactory

```text
final class Nvl\Taxonomy\Database\Factories\TermTranslationFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<TermTranslation>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Taxonomy\Models\TermTranslation>
public function definition(): array
@return array<model-property<TermTranslation>, mixed>
resolved @return array<model-property<Nvl\Taxonomy\Models\TermTranslation>, mixed>
public function forTerm(Nvl\Taxonomy\Models\Term $parent): static
```

## Nvl\Taxonomy\Database\Factories\TermableFactory

```text
final class Nvl\Taxonomy\Database\Factories\TermableFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<Termable>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Taxonomy\Models\Termable>
public function definition(): array
@return array<model-property<Termable>, mixed>
resolved @return array<model-property<Nvl\Taxonomy\Models\Termable>, mixed>
public function forOwner(Illuminate\Database\Eloquent\Model $owner): static
public function forTerm(Nvl\Taxonomy\Models\Term $parent): static
```

## Nvl\Taxonomy\Enums\DeleteTermStrategy

```text
enum Nvl\Taxonomy\Enums\DeleteTermStrategy implements BackedEnum, UnitEnum: string
public const Nvl\Taxonomy\Enums\DeleteTermStrategy Cascade=Nvl\Taxonomy\Enums\DeleteTermStrategy::Cascade="cascade"
public const Nvl\Taxonomy\Enums\DeleteTermStrategy Detach=Nvl\Taxonomy\Enums\DeleteTermStrategy::Detach="detach"
public const Nvl\Taxonomy\Enums\DeleteTermStrategy Reparent=Nvl\Taxonomy\Enums\DeleteTermStrategy::Reparent="reparent"
public const Nvl\Taxonomy\Enums\DeleteTermStrategy Restrict=Nvl\Taxonomy\Enums\DeleteTermStrategy::Restrict="restrict"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Taxonomy\Enums\TaxonomyResponseCode

```text
enum Nvl\Taxonomy\Enums\TaxonomyResponseCode implements BackedEnum, Nvl\Support\Contracts\ResponseCode, UnitEnum: string
public const Nvl\Taxonomy\Enums\TaxonomyResponseCode AmbiguousTermReference=Nvl\Taxonomy\Enums\TaxonomyResponseCode::AmbiguousTermReference="ambiguous_term_reference"
public const Nvl\Taxonomy\Enums\TaxonomyResponseCode BatchReadUnavailable=Nvl\Taxonomy\Enums\TaxonomyResponseCode::BatchReadUnavailable="batch_read_unavailable"
public const Nvl\Taxonomy\Enums\TaxonomyResponseCode CircularHierarchy=Nvl\Taxonomy\Enums\TaxonomyResponseCode::CircularHierarchy="circular_hierarchy"
public const Nvl\Taxonomy\Enums\TaxonomyResponseCode ClosedVocabulary=Nvl\Taxonomy\Enums\TaxonomyResponseCode::ClosedVocabulary="closed_vocabulary"
public const Nvl\Taxonomy\Enums\TaxonomyResponseCode DuplicateSiblingSlug=Nvl\Taxonomy\Enums\TaxonomyResponseCode::DuplicateSiblingSlug="duplicate_sibling_slug"
public const Nvl\Taxonomy\Enums\TaxonomyResponseCode FlatVocabulary=Nvl\Taxonomy\Enums\TaxonomyResponseCode::FlatVocabulary="flat_vocabulary"
public const Nvl\Taxonomy\Enums\TaxonomyResponseCode InvalidParent=Nvl\Taxonomy\Enums\TaxonomyResponseCode::InvalidParent="invalid_parent"
public const Nvl\Taxonomy\Enums\TaxonomyResponseCode InvalidTermOperation=Nvl\Taxonomy\Enums\TaxonomyResponseCode::InvalidTermOperation="invalid_term_operation"
public const Nvl\Taxonomy\Enums\TaxonomyResponseCode MaximumDepthExceeded=Nvl\Taxonomy\Enums\TaxonomyResponseCode::MaximumDepthExceeded="maximum_depth_exceeded"
public const Nvl\Taxonomy\Enums\TaxonomyResponseCode OperationFailed=Nvl\Taxonomy\Enums\TaxonomyResponseCode::OperationFailed="operation_failed"
public const Nvl\Taxonomy\Enums\TaxonomyResponseCode StaleTermVersion=Nvl\Taxonomy\Enums\TaxonomyResponseCode::StaleTermVersion="stale_term_version"
public const Nvl\Taxonomy\Enums\TaxonomyResponseCode TermNotFound=Nvl\Taxonomy\Enums\TaxonomyResponseCode::TermNotFound="term_not_found"
public const Nvl\Taxonomy\Enums\TaxonomyResponseCode UnknownTaxonomy=Nvl\Taxonomy\Enums\TaxonomyResponseCode::UnknownTaxonomy="unknown_taxonomy"
public const Nvl\Taxonomy\Enums\TaxonomyResponseCode UnsafeTermDeletion=Nvl\Taxonomy\Enums\TaxonomyResponseCode::UnsafeTermDeletion="unsafe_term_deletion"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Taxonomy\Enums\TermChangeOperation

```text
enum Nvl\Taxonomy\Enums\TermChangeOperation implements BackedEnum, UnitEnum: string
public const Nvl\Taxonomy\Enums\TermChangeOperation Created=Nvl\Taxonomy\Enums\TermChangeOperation::Created="created"
public const Nvl\Taxonomy\Enums\TermChangeOperation Deleted=Nvl\Taxonomy\Enums\TermChangeOperation::Deleted="deleted"
public const Nvl\Taxonomy\Enums\TermChangeOperation Merged=Nvl\Taxonomy\Enums\TermChangeOperation::Merged="merged"
public const Nvl\Taxonomy\Enums\TermChangeOperation Moved=Nvl\Taxonomy\Enums\TermChangeOperation::Moved="moved"
public const Nvl\Taxonomy\Enums\TermChangeOperation Reordered=Nvl\Taxonomy\Enums\TermChangeOperation::Reordered="reordered"
public const Nvl\Taxonomy\Enums\TermChangeOperation Updated=Nvl\Taxonomy\Enums\TermChangeOperation::Updated="updated"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Taxonomy\Events\TermChanged

```text
final readonly class Nvl\Taxonomy\Events\TermChanged implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(string $termId, string $taxonomy, Nvl\Taxonomy\Enums\TermChangeOperation $operation, int $revision, int $schemaVersion=1): mixed
public function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public function schemaVersion(): int
public readonly Nvl\Taxonomy\Enums\TermChangeOperation $operation
public readonly int $revision
public readonly int $schemaVersion
public readonly string $taxonomy
public readonly string $termId
```

## Nvl\Taxonomy\Exceptions\AmbiguousTermReferenceException

```text
final class Nvl\Taxonomy\Exceptions\AmbiguousTermReferenceException extends Nvl\Taxonomy\Exceptions\TaxonomyException
public static function forSlug(string $taxonomy, string $slug): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Taxonomy\Exceptions\CircularHierarchyException

```text
class Nvl\Taxonomy\Exceptions\CircularHierarchyException extends Nvl\Taxonomy\Exceptions\TaxonomyException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Taxonomy\Exceptions\ClosedVocabularyException

```text
class Nvl\Taxonomy\Exceptions\ClosedVocabularyException extends Nvl\Taxonomy\Exceptions\TaxonomyException
public function __construct(string $taxonomy, array $missingSlugs): mixed
@param list<string> $missingSlugs
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Taxonomy\Exceptions\DuplicateSiblingSlugException

```text
final class Nvl\Taxonomy\Exceptions\DuplicateSiblingSlugException extends Nvl\Taxonomy\Exceptions\TaxonomyException
public static function forSlug(string $taxonomy, string $slug): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Taxonomy\Exceptions\FlatVocabularyException

```text
final class Nvl\Taxonomy\Exceptions\FlatVocabularyException extends Nvl\Taxonomy\Exceptions\TaxonomyException
public static function forTaxonomy(string $taxonomy): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Taxonomy\Exceptions\InvalidParentException

```text
final class Nvl\Taxonomy\Exceptions\InvalidParentException extends Nvl\Taxonomy\Exceptions\TaxonomyException
public static function forTerm(string $taxonomy, string $parentId): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Taxonomy\Exceptions\InvalidTermOperationException

```text
final class Nvl\Taxonomy\Exceptions\InvalidTermOperationException extends InvalidArgumentException implements Nvl\Support\Contracts\RespondableException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Taxonomy\Exceptions\MaximumDepthExceededException

```text
final class Nvl\Taxonomy\Exceptions\MaximumDepthExceededException extends Nvl\Taxonomy\Exceptions\TaxonomyException
public static function forTaxonomy(string $taxonomy, int $maximumDepth): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Taxonomy\Exceptions\StaleTermVersionException

```text
final class Nvl\Taxonomy\Exceptions\StaleTermVersionException extends Nvl\Taxonomy\Exceptions\TaxonomyException
public static function forTerm(string $id): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Taxonomy\Exceptions\TaxonomyBatchReadException

```text
final class Nvl\Taxonomy\Exceptions\TaxonomyBatchReadException extends Nvl\Taxonomy\Exceptions\TaxonomyException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Taxonomy\Exceptions\TaxonomyException

```text
class Nvl\Taxonomy\Exceptions\TaxonomyException extends Exception implements Nvl\Support\Contracts\RespondableException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Taxonomy\Exceptions\TermNotFoundException

```text
final class Nvl\Taxonomy\Exceptions\TermNotFoundException extends InvalidArgumentException implements Nvl\Support\Contracts\RespondableException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Taxonomy\Exceptions\UnknownTaxonomyException

```text
class Nvl\Taxonomy\Exceptions\UnknownTaxonomyException extends Nvl\Taxonomy\Exceptions\TaxonomyException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Taxonomy\Exceptions\UnsafeTermDeletionException

```text
final class Nvl\Taxonomy\Exceptions\UnsafeTermDeletionException extends Nvl\Taxonomy\Exceptions\TaxonomyException
public static function because(string $reason): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Taxonomy\Models\Term

```text
class Nvl\Taxonomy\Models\Term extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable, Nvl\Translatable\Contracts\TranslatableModel
public function ancestors(): Illuminate\Database\Eloquent\Collection
@return Collection<int, static>
resolved @return Illuminate\Database\Eloquent\Collection<int, Nvl\Taxonomy\Models\Term>
public function attachments(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<Termable, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Taxonomy\Models\Termable, $this>
public function children(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<static, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Taxonomy\Models\Term, $this>
public function delete(): ?bool
public function descendants(): Illuminate\Database\Eloquent\Collection
@return Collection<int, static>
resolved @return Illuminate\Database\Eloquent\Collection<int, Nvl\Taxonomy\Models\Term>
public function displayDescription(?string $locale=null): ?string
public function displayName(?string $locale=null): string
public function entries(string $type): Illuminate\Database\Eloquent\Relations\MorphToMany
@param class-string<Model> $type
resolved @param class-string<Illuminate\Database\Eloquent\Model> $type
@return MorphToMany<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\MorphToMany<Illuminate\Database\Eloquent\Model, $this>
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getAllTranslations(): Illuminate\Database\Eloquent\Collection
@return Collection<int, Model>
resolved @return Illuminate\Database\Eloquent\Collection<int, Illuminate\Database\Eloquent\Model>
public function getAvailableLocales(): array
@return list<string>
public function getConnectionName(): ?string
public function getCurrentLocale(): string
public function getRelationValue(mixed $key): mixed
@param string $key
public function getTable(): string
public function getTranslatedAttributes(?string $locale=null): array
@return array<string, mixed>
public function getTranslation(?string $locale=null, bool $withFallback=true): ?Illuminate\Database\Eloquent\Model
public function hasTranslation(?string $locale=null): bool
public function isTranslatableAttribute(string $key): bool
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function parent(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<static, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Taxonomy\Models\Term, $this>
public function refresh(): static
public function refreshForUpdate(): static
public function resolveRouteBinding(mixed $value, mixed $field=null): ?Nvl\Taxonomy\Models\Term
@param mixed $value
@param (string | null) $field
public function resolveTranslation(string $field, ?string $locale=null): Nvl\Translatable\TranslationResolution
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function scopeOrderByTranslated(Illuminate\Database\Eloquent\Builder $query, string $field, string $direction="asc", ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term>
public function scopeOrdered(Illuminate\Database\Eloquent\Builder $query, ?string $taxonomy=null): void
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term> $query
public function scopeWhereTranslated(Illuminate\Database\Eloquent\Builder $query, string $field, mixed $operator, mixed $value=\Nvl\Translatable\Enums\TranslationComparisonValue::Omitted, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term>
public function scopeWhereTranslationNotNull(Illuminate\Database\Eloquent\Builder $query, string $field, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term>
public function scopeWhereTranslationNull(Illuminate\Database\Eloquent\Builder $query, string $field, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term>
public function scopeWithAllTranslations(Illuminate\Database\Eloquent\Builder $query): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term>
public function scopeWithResolvedTranslations(Illuminate\Database\Eloquent\Builder $query, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term>
public function setLocale(string $locale): self
public static function taxonomyName(): string
public function translated(string $field, ?string $locale=null): mixed
public function translation(?string $locale=null): Illuminate\Database\Eloquent\Relations\HasOne
@return HasOne<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasOne<Illuminate\Database\Eloquent\Model, $this>
public function translationDefinition(): Nvl\Translatable\RelatedTranslationDefinition
public function translationResourceKey(): int|string
public function translations(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Illuminate\Database\Eloquent\Model, $this>
@method static Builder<static> orderByTranslated(string $field, string $direction = 'asc', ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term> orderByTranslated(string $field, string $direction = 'asc', ?string $locale = null)
@method static Builder<static> whereTranslated(string $field, mixed $operator, mixed $value = TranslationComparisonValue::Omitted, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term> whereTranslated(string $field, mixed $operator, mixed $value = Nvl\Translatable\Enums\TranslationComparisonValue::Omitted, ?string $locale = null)
@method static Builder<static> whereTranslationNotNull(string $field, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term> whereTranslationNotNull(string $field, ?string $locale = null)
@method static Builder<static> whereTranslationNull(string $field, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term> whereTranslationNull(string $field, ?string $locale = null)
@method static Builder<static> withAllTranslations()
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term> withAllTranslations()
@method static Builder<static> withResolvedTranslations(?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Term> withResolvedTranslations(?string $locale = null)
@property-read Collection<int, Termable> $attachments
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Nvl\Taxonomy\Models\Termable> $attachments
@property-read Collection<int, Term> $children
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Nvl\Taxonomy\Models\Term> $children
@property (Carbon | null) $created_at
resolved @property (Illuminate\Support\Carbon | null) $created_at
@property string $id
@property (array<string, mixed> | null) $meta
@property (string | null) $owner_scope_key
@property-read (Term | null) $parent
resolved @property-read (Nvl\Taxonomy\Models\Term | null) $parent
@property (string | null) $parent_id
@property string $parent_key
@property int $position
@property int $revision
@property string $slug
@property string $taxonomy
@property-read Collection<int, TermTranslation> $translations
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Nvl\Taxonomy\Models\TermTranslation> $translations
@property (Carbon | null) $updated_at
resolved @property (Illuminate\Support\Carbon | null) $updated_at
```

## Nvl\Taxonomy\Models\TermTranslation

```text
final class Nvl\Taxonomy\Models\TermTranslation extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\TermTranslation>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function term(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Term, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Taxonomy\Models\Term, $this>
@property (Carbon | null) $created_at
resolved @property (Illuminate\Support\Carbon | null) $created_at
@property (string | null) $description
@property string $id
@property string $locale
@property string $name
@property (string | null) $owner_scope_key
@property-read Term $term
resolved @property-read Nvl\Taxonomy\Models\Term $term
@property string $term_id
@property (Carbon | null) $updated_at
resolved @property (Illuminate\Support\Carbon | null) $updated_at
```

## Nvl\Taxonomy\Models\Termable

```text
final class Nvl\Taxonomy\Models\Termable extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Taxonomy\Models\Termable>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function term(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<Term, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Taxonomy\Models\Term, $this>
public function termable(): Illuminate\Database\Eloquent\Relations\MorphTo
@return MorphTo<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, $this>
@property (Carbon | null) $created_at
resolved @property (Illuminate\Support\Carbon | null) $created_at
@property string $id
@property (string | null) $owner_scope_key
@property int $position
@property string $taxonomy
@property-read Term $term
resolved @property-read Nvl\Taxonomy\Models\Term $term
@property string $term_id
@property-read Model $termable
resolved @property-read Illuminate\Database\Eloquent\Model $termable
@property (int | string) $termable_id
@property string $termable_type
@property (Carbon | null) $updated_at
resolved @property (Illuminate\Support\Carbon | null) $updated_at
```

## Nvl\Taxonomy\Services\TaxonomyAssignments

```text
final readonly class Nvl\Taxonomy\Services\TaxonomyAssignments
public function __construct(Nvl\Taxonomy\Services\TermAttachmentWriter $attachments, Nvl\Taxonomy\Ownership\TaxonomyBoundary $boundary, Nvl\Taxonomy\Services\TaxonomyActorPolicy $actors, Nvl\Taxonomy\Services\TaxonomyOwnerRegistry $owners): mixed
public function attach(Illuminate\Database\Eloquent\Model $owner, string $taxonomy, array $terms, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): void
@param list<(Term | string)> $terms
resolved @param list<(Nvl\Taxonomy\Models\Term | string)> $terms
public function detach(Illuminate\Database\Eloquent\Model $owner, string $taxonomy, array $terms, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): int
@param list<(Term | string)> $terms
resolved @param list<(Nvl\Taxonomy\Models\Term | string)> $terms
public function sync(Illuminate\Database\Eloquent\Model $owner, string $taxonomy, array $terms, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): void
@param list<(Term | string)> $terms
resolved @param list<(Nvl\Taxonomy\Models\Term | string)> $terms
```

## Nvl\Taxonomy\Services\TaxonomyOwnerRegistry

```text
final class Nvl\Taxonomy\Services\TaxonomyOwnerRegistry
public function __construct(Nvl\Taxonomy\Ownership\TaxonomyBoundary $boundary, Nvl\Support\OwnerRegistry $identitiesRegistry): mixed
public function aliasFor(Illuminate\Database\Eloquent\Model $owner): string
public function all(): array
@return array<string, class-string<Model>>
resolved @return array<string, class-string<Illuminate\Database\Eloquent\Model>>
public function model(string $reference): string
@return class-string<Model>
resolved @return class-string<Illuminate\Database\Eloquent\Model>
public function register(string $alias, string $model): void
public function resolve(Illuminate\Database\Eloquent\Model $owner, bool $lock=false): Illuminate\Database\Eloquent\Model
public function types(): array
@return array<string, class-string<Model>>
resolved @return array<string, class-string<Illuminate\Database\Eloquent\Model>>
public function unknownReferences(array $references): array
@param list<string> $references
@return list<string>
```

## Nvl\Taxonomy\Services\TaxonomyReader

```text
final readonly class Nvl\Taxonomy\Services\TaxonomyReader
public function __construct(Nvl\Taxonomy\Services\TermResolver $resolver, Nvl\Taxonomy\Services\OwnerTaxonomyBatchReader $owners, Nvl\Taxonomy\Services\TaxonomyTree $trees, Nvl\Taxonomy\Support\TaxonomyRegistry $taxonomies, Nvl\Taxonomy\Contracts\TaxonomyCatalogAuthorization $catalogAuthorization, Nvl\Taxonomy\Services\TaxonomyActorPolicy $actors): mixed
public function forOwners(array $owners, array $vocabularies, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?string $locale=null): array
@param list<Model> $owners
resolved @param list<Illuminate\Database\Eloquent\Model> $owners
@param list<string> $vocabularies
@return array{owners: \stdClass, order: list<array{type: string, id: string}>}
resolved @return array{owners: stdClass, order: list<array{type: string, id: string}>}
public function resolve(string $taxonomy, array $references, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): array
@param list<(Term | string)> $references
resolved @param list<(Nvl\Taxonomy\Models\Term | string)> $references
@return list<Term>
resolved @return list<Nvl\Taxonomy\Models\Term>
public function term(string $taxonomy, string $id, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?string $locale=null): ?Nvl\Taxonomy\Models\Term
public function tree(string $taxonomy, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?string $locale=null, int $limit=500): Illuminate\Database\Eloquent\Collection
@return Collection<int, Term>
resolved @return Illuminate\Database\Eloquent\Collection<int, Nvl\Taxonomy\Models\Term>
```

## Nvl\Taxonomy\Services\TaxonomyTerms

```text
final readonly class Nvl\Taxonomy\Services\TaxonomyTerms
public function __construct(Nvl\Taxonomy\Services\TermWriter $writer, Nvl\Taxonomy\Support\TaxonomyRegistry $taxonomies, Nvl\Taxonomy\Services\TermModelResolver $terms, Nvl\Taxonomy\Services\TermHierarchy $hierarchy, Nvl\Taxonomy\Services\TermMergeValidator $validator, Nvl\Taxonomy\Services\TaxonomyOwnerRegistry $owners, Nvl\Support\Events\DomainEventDispatcher $domainEvents, Nvl\Taxonomy\Services\TaxonomyActorPolicy $actors): mixed
public function create(array $attributes, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): Nvl\Taxonomy\Models\Term
@param array<string, mixed> $attributes
public function delete(Nvl\Taxonomy\Models\Term|string $term, int $expectedRevision, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Taxonomy\Enums\DeleteTermStrategy $strategy=\Nvl\Taxonomy\Enums\DeleteTermStrategy::Restrict, ?string $reparentTo=null): bool
public function merge(Nvl\Taxonomy\Models\Term|string $source, Nvl\Taxonomy\Models\Term|string $destination, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, int $expectedSourceRevision, int $expectedDestinationRevision): Nvl\Taxonomy\Models\Term
public function move(Nvl\Taxonomy\Models\Term|string $term, Nvl\Taxonomy\Models\Term|string|null $parent, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, int $position, int $expectedRevision): Nvl\Taxonomy\Models\Term
public function rebuild(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?string $taxonomy=null, bool $dryRun=false): int
public function update(Nvl\Taxonomy\Models\Term|string $term, array $attributes, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Translatable\Enums\TranslationSyncMode $mode=\Nvl\Translatable\Enums\TranslationSyncMode::Patch): Nvl\Taxonomy\Models\Term
@param array<string, mixed> $attributes
public function validateMerge(Nvl\Taxonomy\Models\Term|string $source, Nvl\Taxonomy\Models\Term|string $destination, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, int $expectedSourceRevision, int $expectedDestinationRevision): void
```

## Nvl\Taxonomy\Services\TermModelRegistry

```text
final class Nvl\Taxonomy\Services\TermModelRegistry
public function __construct(Illuminate\Contracts\Config\Repository $configuration, Nvl\Support\OwnerRegistry $identities): mixed
public function termClass(): string
@return class-string<Term>
resolved @return class-string<Nvl\Taxonomy\Models\Term>
```

## Nvl\Taxonomy\Support\TaxonomyDefinition

```text
final readonly class Nvl\Taxonomy\Support\TaxonomyDefinition
public function __construct(string $taxonomy, string $model, bool $hierarchical, bool $exclusive, bool $open, int $maxDepth, string $sort, array $allowedOwners, array $metadataRules): mixed
@param class-string<Term> $model
resolved @param class-string<Nvl\Taxonomy\Models\Term> $model
@param list<string> $allowedOwners
@param array<string, mixed> $metadataRules
public readonly array $allowedOwners
@param list<string> $allowedOwners
public readonly bool $exclusive
public readonly bool $hierarchical
public readonly int $maxDepth
public readonly array $metadataRules
@param array<string, mixed> $metadataRules
public readonly string $model
@param class-string<Term> $model
resolved @param class-string<Nvl\Taxonomy\Models\Term> $model
public readonly bool $open
public readonly string $sort
public readonly string $taxonomy
```

## Nvl\Taxonomy\Support\TaxonomyRegistry

```text
final class Nvl\Taxonomy\Support\TaxonomyRegistry
public function __construct(): mixed
public function all(): array
@return array<string, TaxonomyDefinition>
resolved @return array<string, Nvl\Taxonomy\Support\TaxonomyDefinition>
public function get(string $taxonomy): Nvl\Taxonomy\Support\TaxonomyDefinition
public function register(Nvl\Taxonomy\Support\TaxonomyDefinition $definition): void
```
