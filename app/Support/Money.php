<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Money is integer cents everywhere in this app. These helpers exist so no
 * float ever reaches the database.
 */
final class Money
{
    public static function fromDollars(float|string $dollars): int
    {
        return (int) round(((float) $dollars) * 100);
    }

    public static function toDollars(int $cents): float
    {
        return $cents / 100;
    }

    public static function format(int $cents, bool $signed = false): string
    {
        $sign = $cents < 0 ? '-' : ($signed && $cents > 0 ? '+' : '');

        return $sign.'$'.number_format(abs($cents) / 100, 2);
    }

    /** Stake expressed in units, for the pick cards. */
    public static function toUnits(int $cents, int $unitSizeCents): float
    {
        if ($unitSizeCents <= 0) {
            return 0.0;
        }

        return round($cents / $unitSizeCents, 2);
    }

    public static function fromUnits(float $units, int $unitSizeCents): int
    {
        return (int) round($units * $unitSizeCents);
    }

    public static function pctOf(int $cents, float $pct): int
    {
        return (int) floor($cents * ($pct / 100));
    }
}
