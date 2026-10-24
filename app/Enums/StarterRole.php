<?php

declare(strict_types=1);

namespace App\Enums;

enum StarterRole: string
{
    case Pitcher = 'pitcher';
    case Goalie = 'goalie';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $r) => $r->value, self::cases());
    }
}
