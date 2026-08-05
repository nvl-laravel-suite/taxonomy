<?php

declare(strict_types=1);

namespace Nvl\Taxonomy\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

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
        $table = config("taxonomy.table_names.{$key}", $default);

        if (! is_string($table)
            || preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/D', $table) !== 1) {
            throw new InvalidArgumentException("Taxonomy table [{$key}] is invalid.");
        }

        return $table;
    }

    /**
     * Return the dedicated taxonomy connection when configured.
     */
    public static function connection(): ?string
    {
        $connection = config('taxonomy.storage.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
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
    public static function attachmentLockName(Model $owner, string $taxonomy): string
    {
        return 'taxonomy:attachments:'.hash('sha256', implode('|', [
            $owner->getMorphClass(),
            self::modelIdentifier($owner),
            $taxonomy,
        ]));
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
