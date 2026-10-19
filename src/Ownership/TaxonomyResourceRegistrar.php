<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Ownership;

use InvalidArgumentException;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Taxonomy\Models\Termable;
use Nvl\Taxonomy\Models\TermTranslation;
use Nvl\Taxonomy\Services\TermModelRegistry;

/**
 * Compiles the configured structural root and its canonical host attachment graph.
 *
 * @internal
 */
final readonly class TaxonomyResourceRegistrar
{
    public function __construct(private TermModelRegistry $models) {}

    public function register(OwnershipResourceRegistry $resources): void
    {
        $class = $this->models->termClass();
        $owned = $class::ownershipDefinition()->strategy !== 'unscoped';
        $parents = config('nvl-taxonomy.ownership.owner_resources', []);
        if (! is_array($parents) || ! array_is_list($parents)) {
            throw new InvalidArgumentException('Taxonomy hosts require explicit resource keys.');
        }
        foreach ($parents as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Invalid Taxonomy host resource.');
            }
        }
        $resources->register(new OwnershipResourceDefinition('taxonomy.terms', 'taxonomy', $class, $class::ownershipDefinition(), immutableFields: $owned ? ['taxonomy'] : []));
        $resources->register(new OwnershipResourceDefinition('taxonomy.translations', 'taxonomy', TermTranslation::class, $owned ? OwnershipDefinition::inherited('term') : OwnershipDefinition::unscoped(), parentResource: $owned ? 'taxonomy.terms' : null));
        $resources->register(new OwnershipResourceDefinition('taxonomy.attachments', 'taxonomy', Termable::class, $owned ? OwnershipDefinition::inherited('termable') : OwnershipDefinition::unscoped(), parentResources: $owned ? $parents : [], immutableFields: $owned ? ['taxonomy'] : []));
    }
}
