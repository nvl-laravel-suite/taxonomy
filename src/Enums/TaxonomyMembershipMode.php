<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Enums;

/** Determines how a reviewed membership proposal may affect assignments.
 * @api
 */
enum TaxonomyMembershipMode: string
{
    case Apply = 'apply';
    case Shadow = 'shadow';
}
