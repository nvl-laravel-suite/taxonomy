<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Enums;

/**
 * Defines the supported handling strategies for a term deletion.
 */
enum DeleteTermStrategy: string
{
    case Restrict = 'restrict';
    case Detach = 'detach';
    case Cascade = 'cascade';
    case Reparent = 'reparent';
}
