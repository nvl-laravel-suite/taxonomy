<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Taxonomy\Models\Term;

/**
 * Builds Term fixture rows and their declared package parents.
 *
 * @extends Factory<Term>
 *
 * @api
 */
final class TermFactory extends Factory
{
    protected $model = Term::class;

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
        })->afterMaking(function (Term $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            FactoryGuard::root($model, 'taxonomy.terms');
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<Term>, mixed>
     */
    public function definition(): array
    {
        return [
            'taxonomy' => 'category',
            'slug' => $this->faker->unique()->slug(3),
            'position' => 0,
            'revision' => 1,
        ];
    }

    /** Build the built-in category vocabulary fixture.
     *
     * @api
     */
    public function category(): static
    {
        return $this->state(['taxonomy' => 'category']);
    }

    /** Build the built-in tag vocabulary fixture.
     *
     * @api
     */
    public function tag(): static
    {
        return $this->state(['taxonomy' => 'tag']);
    }
}
