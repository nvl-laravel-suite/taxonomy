<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Support\Models\ConfiguredModelRegistration;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Taxonomy\Models\Term;

/**
 * Resolves the one configured Term extension through the shared native model identity.
 *
 * @api
 */
final class TermModelRegistry
{
    /** @var ConfiguredModelRegistration<Term> */
    private readonly ConfiguredModelRegistration $registration;

    /** Retain deployment configuration and the shared identity registry. */
    public function __construct(
        Repository $configuration,
        OwnerRegistry $identities,
    ) {
        $this->registration = new ConfiguredModelRegistration(
            $configuration,
            $identities,
            'nvl-taxonomy.models.term',
            Term::class,
            'taxonomy.terms',
            static function (string $class): void {
                if (! in_array($class::ownershipDefinition()->strategy, ['unscoped', 'column', 'pivot'], true)) {
                    throw new OwnershipConfigurationInvalid('Term roots require a native column or concrete primary pivot.');
                }
            },
        );
    }

    /** Resolve and freeze the concrete Term class before resource compilation.
     * @internal
     *
     * @return class-string<Term>
     */
    public function prepare(): string
    {
        return $this->registration->prepare();
    }

    /** Validate native capabilities after Laravel installs Eloquent's dispatcher.
     * @return class-string<Term>
     */
    public function termClass(): string
    {
        return $this->registration->modelClass();
    }

    /** Resolve the immutable model from native framework entry points.
     * @internal
     *
     * @return class-string<Term>
     */
    public static function modelClass(): string
    {
        return Container::getInstance()->make(self::class)->termClass();
    }

    /** Construct the configured root.
     * @internal
     *
     * @param  array<string,mixed>  $attributes
     */
    public static function newInstance(array $attributes = []): Term
    {
        $class = Container::getInstance()->make(self::class)->termClass();

        return new $class($attributes);
    }

    /** Persist a package-authored attribute map under the exact source assignment.
     * @internal
     *
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

    /** Create the exact configured query.
     * @internal
     *
     * @return Builder<Term>
     */
    public static function query(): Builder
    {
        return self::newInstance()->newQuery();
    }
}
