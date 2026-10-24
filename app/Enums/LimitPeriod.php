<?php

declare(strict_types=1);

namespace App\Enums;

enum LimitPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $p) => $p->value, self::cases());
    }
}
