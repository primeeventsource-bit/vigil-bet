<?php

declare(strict_types=1);

namespace App\Enums;

enum BetStatus: string
{
    case Pending = 'pending';
    case Won = 'won';
    case Lost = 'lost';
    case Push = 'push';
    case Void = 'void';
    case CashedOut = 'cashed_out';

    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $s) => $s->value, self::cases());
    }
}
