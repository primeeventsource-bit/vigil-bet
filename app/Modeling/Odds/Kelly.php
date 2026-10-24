<?php

declare(strict_types=1);

namespace App\Modeling\Odds;

/**
 * Kelly staking.
 *
 * Full Kelly is the mathematically optimal growth rate if your probability is
 * exactly right. It never is, so this app bets a fraction of it. A quarter
 * Kelly gives up a little growth for a large reduction in drawdown, and it
 * stays survivable when the model is wrong about its own confidence - which
 * is the normal case, not the exception.
 */
final class Kelly
{
    /**
     * Full Kelly fraction of bankroll.
     *
     * f = (b * p - q) / b, where b is profit per unit staked.
     * Returns 0 when there is no edge: this never recommends a negative bet.
     */
    public static function full(float $probability, int $american): float
    {
        if ($probability <= 0.0 || $probability >= 1.0) {
            return 0.0;
        }

        $b = OddsConverter::payoutMultiplier($american);

        if ($b <= 0.0) {
            return 0.0;
        }

        $fraction = (($b * $probability) - (1.0 - $probability)) / $b;

        return max(0.0, $fraction);
    }

    /** Full Kelly scaled down by the configured fraction, eg 0.25. */
    public static function fractional(float $probability, int $american, float $kellyFraction): float
    {
        return self::full($probability, $american) * max(0.0, $kellyFraction);
    }

    /** The stake that fraction implies, in whole cents, rounded down. */
    public static function stakeCents(float $probability, int $american, float $kellyFraction, int $bankrollCents): int
    {
        if ($bankrollCents <= 0) {
            return 0;
        }

        return (int) floor($bankrollCents * self::fractional($probability, $american, $kellyFraction));
    }
}
