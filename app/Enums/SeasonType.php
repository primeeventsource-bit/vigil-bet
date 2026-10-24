<?php

declare(strict_types=1);

namespace App\Enums;

enum SeasonType: string
{
    case Preseason = 'pre';
    case Regular = 'reg';
    case Postseason = 'post';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $s) => $s->value, self::cases());
    }
}
