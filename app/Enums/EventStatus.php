<?php

declare(strict_types=1);

namespace App\Enums;

enum EventStatus: string
{
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Final = 'final';
    case Postponed = 'postponed';
    case Canceled = 'canceled';

    public function isSettled(): bool
    {
        return $this === self::Final;
    }

    public function isGradable(): bool
    {
        return $this === self::Final || $this === self::Canceled;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $s) => $s->value, self::cases());
    }
}
