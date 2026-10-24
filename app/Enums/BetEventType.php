<?php

declare(strict_types=1);

namespace App\Enums;

enum BetEventType: string
{
    case Placed = 'placed';
    case Graded = 'graded';
    case Voided = 'voided';
    case Pushed = 'pushed';
    case CashOut = 'cash_out';
    case Corrected = 'corrected';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $t) => $t->value, self::cases());
    }
}
