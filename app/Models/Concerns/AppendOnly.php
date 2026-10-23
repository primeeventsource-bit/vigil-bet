<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use RuntimeException;

/**
 * Append-only history, enforced in the application as well as the
 * database, and failing loudly rather than silently discarding the write.
 *
 * Postgres gets triggers for this in production. This trait is what makes
 * the same rule true in a SQLite test run, which is where the mistake
 * actually gets made.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function ($model): void {
            throw new RuntimeException(
                class_basename($model).' is append-only; update it by writing a new row.'
            );
        });

        static::deleting(function ($model): void {
            throw new RuntimeException(
                class_basename($model).' is append-only; rows are never deleted.'
            );
        });
    }
}
