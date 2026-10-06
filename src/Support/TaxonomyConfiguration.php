<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Nvl\Support\Config\PackageOptions;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Taxonomy\Definitions\Tables\TaxonomyTables;

/**
 * Normalizes package configuration and polymorphic identifiers at infrastructure boundaries.
 */
final class TaxonomyConfiguration
{
    /**
     * Return one validated configurable table name.
     */
    public static function table(string $key, string $default): string
    {
        return TaxonomyTables::get($key);
    }

    /**
     * Return the dedicated taxonomy connection when configured.
     */
    public static function connection(): ?string
    {
        return PackageStorage::connection('taxonomy');
    }

    /**
     * Return a positive configured resource limit or its safe default.
     */
    public static function positiveLimit(string $key, int $default): int
    {
        $limit = config("taxonomy.limits.{$key}", $default);

        return is_int($limit) && $limit > 0 ? $limit : $default;
    }

    /**
     * Return the deadlock retry attempts for mutation transactions.
     *
     * @return int<1, max>
     */
    public static function transactionAttempts(): int
    {
        return self::positiveInteger('transactions.attempts', 3);
    }

    /**
     * Return the maximum attachment-lock lifetime in seconds.
     */
    public static function lockSeconds(): int
    {
        return self::positiveInteger('locks.seconds', 30);
    }

    /** Return the effective distributed-lock store without changing operation durations. */
    public static function lockStore(): string
    {
        return PackageOptions::lockStore('taxonomy');
    }

    /** Create one atomic lock on the selected store, failing clearly when unsupported. */
    public static function lock(string $name, int $seconds): Lock
    {
        $provider = Cache::store(self::lockStore())->getStore();
        if (! $provider instanceof LockProvider) {
            throw new InvalidArgumentException('The selected Taxonomy lock store must support atomic locks.');
        }

        return $provider->lock($name, $seconds);
    }

    /**
     * Return the attachment-lock acquisition timeout in seconds.
     */
    public static function lockWaitSeconds(): int
    {
        return self::positiveInteger('locks.wait_seconds', 10);
    }

    /**
     * Normalize one persisted model identifier for polymorphic storage.
     */
    public static function modelIdentifier(Model $model): string
    {
        $identifier = $model->getKey();

        if (! is_string($identifier) && ! is_int($identifier)) {
            throw new InvalidArgumentException('Taxonomy owners require a string or integer identifier.');
        }

        $normalized = (string) $identifier;

        if (strlen($normalized) > 191) {
            throw new InvalidArgumentException(
                'Taxonomy owner identifiers may not exceed 191 characters.',
            );
        }

        return $normalized;
    }

    /**
     * Return the stable distributed-lock key for one owner vocabulary set.
     */
    public static function attachmentLockName(TenantBoundary $boundary, Model $owner, string $taxonomy): string
    {
        return $boundary->key('taxonomy.terms', 'attachments:'.hash('sha256', implode('|', [
            $owner->getMorphClass(),
            self::modelIdentifier($owner),
            $taxonomy,
        ])));
    }

    /**
     * @param  int<1, max>  $default
     * @return int<1, max>
     */
    private static function positiveInteger(string $key, int $default): int
    {
        $value = config("taxonomy.{$key}", $default);

        return is_int($value) && $value > 0 ? $value : $default;
    }

    private function __construct() {}
}
