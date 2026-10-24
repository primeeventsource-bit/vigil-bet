<?php

declare(strict_types=1);

namespace App\Enums;

enum ModelStatus: string
{
    case Candidate = 'candidate';
    case Active = 'active';
    case Retired = 'retired';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $s) => $s->value, self::cases());
    }
}
