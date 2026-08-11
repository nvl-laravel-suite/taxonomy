<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Definitions\Tables;

/**
 * Defines the canonical table names owned by the Taxonomy package.
 */
final class TaxonomyTables
{
    public const string Terms = 'terms';

    public const string I18n = 'terms_i18n';

    public const string Termables = 'termables';

    private function __construct() {}
}
