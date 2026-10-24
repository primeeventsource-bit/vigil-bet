<?php

declare(strict_types=1);

namespace App\Enums;

enum TriggerSource: string
{
    case Schedule = 'schedule';
    case Manual = 'manual';
    case Chain = 'chain';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $s) => $s->value, self::cases());
    }
}
