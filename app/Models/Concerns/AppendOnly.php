<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\AppendOnlyViolation;

/**
 * Append-only history. Postgres enforces this with a trigger in production;
 * this trait enforces it everywhere, including SQLite dev and tests, and
 * fails loudly rather than silently discarding the attempt.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function ($model): void {
            throw AppendOnlyViolation::forUpdate($model->getTable());
        });

        static::deleting(function ($model): void {
            throw AppendOnlyViolation::forDelete($model->getTable());
        });
    }
}
