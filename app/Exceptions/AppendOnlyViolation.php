<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class AppendOnlyViolation extends RuntimeException
{
    public static function forUpdate(string $table): self
    {
        return new self(
            "Refusing to update {$table}: this table is append-only. Insert a new row instead."
        );
    }

    public static function forDelete(string $table): self
    {
        return new self(
            "Refusing to delete from {$table}: this table is append-only."
        );
    }
}
