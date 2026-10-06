<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Taxonomy\Models\Term;
use Nvl\Taxonomy\Models\TermTranslation;

/**
 * Builds TermTranslation fixture rows and their declared package parents.
 *
 * @extends Factory<TermTranslation>
 *
 * @api
 */
final class TermTranslationFactory extends Factory
{
    protected $model = TermTranslation::class;

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
        })->afterMaking(function (TermTranslation $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            if ($model->getAttribute('term_id') !== null) {
                $parent = Term::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('term_id')));
                FactoryGuard::parent($parent, $model);
                FactoryGuard::inherit($model, $parent);
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<TermTranslation>, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'locale' => 'en',
            'name' => $this->faker->words(3, true),
        ];
    }

    /**
     * Associate an admitted persisted Term parent.
     *
     * @api
     */
    public function forTerm(Term $parent): static
    {
        FactoryGuard::parent($parent, new TermTranslation);

        return $this->state([
            'term_id' => $parent->getKey(),
        ]);
    }
}
