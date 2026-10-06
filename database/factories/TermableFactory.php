<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\Termable;

/**
 * Builds Termable fixture rows and their declared package parents.
 *
 * @extends Factory<Termable>
 *
 * @api
 */
final class TermableFactory extends Factory
{
    protected $model = Termable::class;

    /**
     * Prepare native parent and owner facts after Laravel expands relationships.
     *
     * @internal
     */
    public function configure(): static
    {
        $expandRelationships = true;

        return $this->state(function () use (&$expandRelationships): array {
            $expandRelationships = $this->expandRelationships;

            return [];
        })->afterMaking(function (Termable $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            $owner = FactoryGuard::owner($model, 'termable_type', 'termable_id');
            FactoryGuard::inherit($model, $owner);
            if ($model->getAttribute('term_id') !== null) {
                $parent = Term::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('term_id')));
                FactoryGuard::parent($parent, $model);
                if (config('nvl-tenancy.enabled') === true && $owner->getRawOriginal('tenant_id') !== $parent->getRawOriginal('tenant_id')) {
                    throw new InvalidArgumentException('Fixture owner and parent tenancy must agree.');
                }
                FactoryGuard::inherit($model, $parent);
                if ($model->taxonomy !== $parent->taxonomy) {
                    throw new InvalidArgumentException('Attachment fixtures require the term taxonomy.');
                }
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<Termable>, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'termable_type' => null,
            'termable_id' => null,
            'taxonomy' => fn (array $attributes): string => $attributes['term_id'] === null ? 'category' : Term::query()->findOrFail(FactoryGuard::identifier($attributes['term_id']))->taxonomy,
            'position' => 0,
        ];
    }

    /**
     * Associate an admitted persisted Term parent.
     *
     * @api
     */
    public function forTerm(Term $parent): static
    {
        FactoryGuard::parent($parent, new Termable);

        return $this->state([
            'term_id' => $parent->getKey(),
            'taxonomy' => $parent->taxonomy,
        ]);
    }

    /**
     * Associate a persisted host owner using its native morph identity.
     *
     * @api
     */
    public function forOwner(Model $owner): static
    {
        FactoryGuard::parent($owner, new Termable);

        return $this->state([
            'termable_type' => $owner->getMorphClass(),
            'termable_id' => (string) FactoryGuard::identifier($owner->getKey()),
        ]);
    }
}
