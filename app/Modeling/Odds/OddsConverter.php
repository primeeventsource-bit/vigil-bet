<?php

declare(strict_types=1);

namespace App\Modeling\Odds;

use InvalidArgumentException;

/**
 * American odds are the stored form; decimal and implied probability are
 * derived. Every conversion here is exact and round-trips, because a cent of
 * drift in a price becomes a fake edge downstream.
 */
final class OddsConverter
{
    /** American odds are never between -100 and +100 exclusive. */
    public static function isValidAmerican(int $american): bool
    {
        return $american <= -100 || $american >= 100;
    }

    public static function assertValidAmerican(int $american): void
    {
        if (! self::isValidAmerican($american)) {
            throw new InvalidArgumentException(
                "Invalid American odds: {$american}. Must be <= -100 or >= +100."
            );
        }
    }

    public static function americanToDecimal(int $american): float
    {
        self::assertValidAmerican($american);

        return $american > 0
            ? 1.0 + ($american / 100.0)
            : 1.0 + (100.0 / abs($american));
    }

    public static function decimalToAmerican(float $decimal): int
    {
        if ($decimal <= 1.0) {
            throw new InvalidArgumentException("Invalid decimal odds: {$decimal}. Must be > 1.");
        }

        $american = $decimal >= 2.0
            ? ($decimal - 1.0) * 100.0
            : -100.0 / ($decimal - 1.0);

        return (int) round($american);
    }

    /** Implied probability including the vig. */
    public static function americanToProbability(int $american): float
    {
        return 1.0 / self::americanToDecimal($american);
    }

    public static function decimalToProbability(float $decimal): float
    {
        if ($decimal <= 1.0) {
            throw new InvalidArgumentException("Invalid decimal odds: {$decimal}. Must be > 1.");
        }

        return 1.0 / $decimal;
    }

    public static function probabilityToDecimal(float $probability): float
    {
        self::assertProbability($probability);

        return 1.0 / $probability;
    }

    /** The fair price for a probability, with no margin added. */
    public static function probabilityToAmerican(float $probability): int
    {
        return self::decimalToAmerican(self::probabilityToDecimal($probability));
    }

    /** Profit per 1 unit staked. */
    public static function payoutMultiplier(int $american): float
    {
        return self::americanToDecimal($american) - 1.0;
    }

    /** Expected value per 1 unit staked at a given true probability. */
    public static function expectedValue(float $trueProbability, int $american): float
    {
        self::assertProbability($trueProbability);

        return ($trueProbability * self::americanToDecimal($american)) - 1.0;
    }

    /**
     * The worst price at which a bet still clears a required edge over the
     * fair probability. This is the price cutoff shown on a pick: if the
     * line moves past it, the bet is no longer worth taking.
     */
    public static function minimumAcceptableAmerican(float $modelProbability, float $requiredEdge): int
    {
        self::assertProbability($modelProbability);

        $impliedCeiling = $modelProbability - $requiredEdge;

        if ($impliedCeiling <= 0.0) {
            throw new InvalidArgumentException(
                'Required edge is larger than the model probability; no price can clear it.'
            );
        }

        return self::decimalToAmerican(1.0 / $impliedCeiling);
    }

    public static function formatAmerican(int $american): string
    {
        return ($american > 0 ? '+' : '').$american;
    }

    private static function assertProbability(float $probability): void
    {
        if ($probability <= 0.0 || $probability >= 1.0) {
            throw new InvalidArgumentException(
                "Probability must be strictly between 0 and 1, got {$probability}."
            );
        }
    }
}
