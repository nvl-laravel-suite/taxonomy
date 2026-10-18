<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Ownership;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipScope;

/** Retains unadopted vocabulary subclasses through V1 while enforcing exact owned root identities. @internal
 * @implements Scope<Model>
 */
final readonly class TaxonomyTermScope implements Scope
{
    /** @param Builder<covariant Model> $builder */
    public function apply(Builder $builder, Model $model): void
    {
        if (TaxonomyOwnership::declared()) {
            $base = $builder->getQuery();
            $from = $base->from;
            $prototype = new ($model::class);
            if (is_string($from) && preg_match('/^'.preg_quote($prototype->getTable(), '/').' as (laravel_reserved_[0-9]+)$/D', $from, $match) === 1) {
                if ($base->getConnection() !== $prototype->getConnection() || $model->getConnection() !== $prototype->getConnection()
                    || $base->unions !== null || ! in_array($model->getTable(), [$prototype->getTable(), $match[1]], true)) {
                    throw new OwnershipViolation('Taxonomy aliases require the canonical native source.');
                }
                $canonical = $prototype->newModelQuery();
                (new OwnershipScope)->apply($canonical, $prototype);
                if ($base->wheres !== []) {
                    $nested = $base->forNestedWhere();
                    $nested->wheres = $base->wheres;
                    $nested->setBindings($base->getRawBindings()['where'], 'where');
                    $base->wheres = [];
                    $base->setBindings([], 'where');
                    $base->addNestedWhereQuery($nested);
                }
                $builder->whereIn($match[1].'.'.$model->getKeyName(), $canonical->select($prototype->getQualifiedKeyName()));
            } else {
                (new OwnershipScope)->apply($builder, $model);
            }

            return;
        }
        $query = $model->newModelQuery();
        $query->setQuery($builder->getQuery());
        Container::getInstance()->make(TaxonomyBoundary::class)->query($query, 'taxonomy.terms');
    }
}
