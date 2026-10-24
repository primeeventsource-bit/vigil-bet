<?php

declare(strict_types=1);

namespace App\Enums;

enum VigMethod: string
{
    case Multiplicative = 'multiplicative';
    case Power = 'power';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $m) => $m->value, self::cases());
    }
}
