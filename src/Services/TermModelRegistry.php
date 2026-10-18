<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Support\Models\ConfiguredModelResolver;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipScope;
use Nvl\Taxonomy\Models\Term;
use ReflectionMethod;

/** Resolves the one configured Term extension through the shared native model identity. @api */
final class TermModelRegistry
{
    /** @var class-string<Term>|null */
    private ?string $definition = null;

    private bool $validated = false;

    /** Retain deployment configuration and the shared identity registry. */
    public function __construct(private readonly Repository $configuration, private readonly OwnerRegistry $identities) {}

    /** Resolve and freeze the concrete Term class before resource compilation. @internal
     * @return class-string<Term>
     */
    public function prepare(): string
    {
        if ($this->definition !== null) {
            return $this->definition;
        }
        $configured = $this->configuration->get('nvl-taxonomy.models.term', Term::class);
        if (! is_string($configured)) {
            throw new OwnershipConfigurationInvalid('Term model configuration must name a concrete Term subclass.');
        }
        $class = (new ConfiguredModelResolver)->resolve($configured, Term::class);
        if (! in_array($class::ownershipDefinition()->strategy, ['unscoped', 'column', 'pivot'], true)) {
            throw new OwnershipConfigurationInvalid('Term roots require a native column or concrete primary pivot.');
        }
        $this->identities->registerReplacement('taxonomy.terms', Term::class, $class);
        $this->definition = $class;

        return $class;
    }

    /** Validate native capabilities after Laravel installs Eloquent's dispatcher.
     * @return class-string<Term>
     */
    public function termClass(): string
    {
        $class = $this->prepare();
        if ($this->validated) {
            return $class;
        }
        $model = new $class;
        $base = new Term;
        foreach ($base->getCasts() as $attribute => $cast) {
            if (($model->getCasts()[$attribute] ?? null) !== $cast) {
                throw new OwnershipConfigurationInvalid('Configured Term models must retain the required casts.');
            }
        }
        foreach (['save', 'delete', 'newQueryForRestoration', 'bootHasOwnership'] as $method) {
            if ((new ReflectionMethod($class, $method))->getDeclaringClass()->getName() !== Term::class) {
                throw new OwnershipConfigurationInvalid('Configured Term models must retain the canonical persistence hooks.');
            }
        }
        if (! $model->hasGlobalScope(OwnershipScope::class)
            || $model->getKeyName() !== $base->getKeyName() || $model->getKeyType() !== 'string' || $model->getIncrementing()) {
            throw new OwnershipConfigurationInvalid('Configured Term models must retain the builder, UUID and ownership capabilities.');
        }
        $this->validated = true;

        return $class;
    }

    /** Resolve the immutable model from native framework entry points. @internal
     * @return class-string<Term>
     */
    public static function modelClass(): string
    {
        return Container::getInstance()->make(self::class)->termClass();
    }

    /** Construct the configured root. @internal
     * @param  array<string,mixed>  $attributes
     */
    public static function newInstance(array $attributes = []): Term
    {
        $class = Container::getInstance()->make(self::class)->termClass();

        return new $class($attributes);
    }

    /** Persist a package-authored attribute map under the exact source assignment. @internal
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes): Term
    {
        return app(OwnershipWriter::class)->create(self::modelClass(), static function (array $ownership) use ($attributes): Term {
            $model = self::newInstance();
            $model->forceFill([...$attributes, ...$ownership])->save();

            return $model;
        });
    }

    /** Create the exact configured query. @internal
     * @return Builder<Term>
     */
    public static function query(): Builder
    {
        return self::newInstance()->newQuery();
    }
}
