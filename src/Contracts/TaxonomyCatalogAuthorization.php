<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Support\Enums\SystemActor;

/**
 * Admits catalogue vocabulary reads without exposing native storage query builders.
 *
 * @api
 */
interface TaxonomyCatalogAuthorization
{
    /** Authorize one vocabulary for the canonical explicit actor before reading any term facts. */
    public function authorizeVocabulary(Authenticatable|SystemActor $actor, string $taxonomy): void;
}
