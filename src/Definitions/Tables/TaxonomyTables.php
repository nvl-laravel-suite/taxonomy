<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Definitions\Tables;

use Nvl\Support\Config\PackageStorage;

/**
 * Defines the canonical table names owned by the Taxonomy package.
 */
final class TaxonomyTables
{
    public const string Terms = 'nvl_taxonomy_terms';

    public const string I18n = 'nvl_taxonomy_i18n';

    public const string Termables = 'nvl_taxonomy_termables';

    public const string MembershipMatches = 'nvl_taxonomy_membership_matches';

    /** Return one configured logical or historical package table. */
    public static function get(string $key): string
    {
        return match ($key) {
            self::MembershipMatches, 'membership_matches' => PackageStorage::table('taxonomy', 'membership_matches', self::MembershipMatches),
            default => PackageStorage::resolveTable('taxonomy', $key),
        };
    }

    private function __construct() {}
}
