<?php

declare(strict_types=1);

namespace App\Enums;

enum BetResult: string
{
    case Win = 'win';
    case Loss = 'loss';
    case Push = 'push';
    case Void = 'void';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $r) => $r->value, self::cases());
    }
}
