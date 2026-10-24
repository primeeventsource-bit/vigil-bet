<?php

declare(strict_types=1);

namespace App\Enums;

enum Market: string
{
    case Moneyline = 'h2h';
    case Spread = 'spreads';
    case Total = 'totals';

    public function label(): string
    {
        return match ($this) {
            self::Moneyline => 'Moneyline',
            self::Spread => 'Spread',
            self::Total => 'Total',
        };
    }

    /** Markets where the stored line is meaningful. */
    public function hasLine(): bool
    {
        return $this !== self::Moneyline;
    }

    /** @return array<int, Side> */
    public function sides(): array
    {
        return match ($this) {
            self::Moneyline, self::Spread => [Side::Home, Side::Away],
            self::Total => [Side::Over, Side::Under],
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $m) => $m->value, self::cases());
    }
}
