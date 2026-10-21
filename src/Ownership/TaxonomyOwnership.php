<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Ownership;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Exceptions\BusinessException;
use Nvl\Support\Ownership\Compatibility\LegacyOwnershipSupport;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipQueueCapture;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/**
 * Resolves canonical metadata without retaining request admission.
 *
 * @internal
 */
final class TaxonomyOwnership
{
    public static function declared(): bool
    {
        return (app(OwnershipResourceRegistry::class)->all()['taxonomy.terms'] ?? null)?->ownership->strategy !== null && app(OwnershipResourceRegistry::class)->get('taxonomy.terms')->ownership->strategy !== 'unscoped';
    }

    /** Preserve the selected authority's public exception contract. */
    public static function violation(string $message): BusinessException
    {
        return self::declared() ? new OwnershipViolation($message) : app(LegacyOwnershipSupport::class)->violation($message);
    }

    public static function active(): bool
    {
        return self::declared() || app(LegacyOwnershipSupport::class)->enabled();
    }

    public static function resource(Model $model): string
    {
        return self::declared() ? app(OwnershipResourceRegistry::class)->forModel($model)->key : app(LegacyOwnershipSupport::class)->resource($model);
    }

    /** Capture scalar admission; untouched graphs preserve disabled installation behavior. */
    public static function envelope(): OwnershipJobEnvelope
    {
        return app(OwnershipQueueCapture::class)->capture();
    }

    private function __construct() {}
}
