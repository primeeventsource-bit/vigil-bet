<?php

declare(strict_types=1);

namespace App\Modeling\Odds;

use App\Enums\VigMethod;
use InvalidArgumentException;

/**
 * Strips the bookmaker margin from a set of prices to get the market fair
 * probability.
 *
 * This is the single most important number in the app. It is the benchmark
 * every model is scored against, and it is what an edge is measured from. A
 * two-way market at -110 each way implies 104.76 percent; that extra 4.76
 * is the hold, and leaving it in would make the market look beatable by
 * about five points when it is not.
 *
 * Two methods, because they disagree in a way that matters:
 *
 *  - Multiplicative divides every implied probability by the total. Simple,
 *    and it assumes the hold is spread evenly in proportion to price.
 *  - Power finds the exponent k where the implied probabilities sum to one.
 *    It takes proportionally more margin out of longshots, which is what
 *    books actually do - the favourite-longshot bias is real, and on a big
 *    underdog the two methods can differ by several points.
 */
final class VigRemover
{
    /**
     * @param  array<string, int>  $americanBySide
     * @return array<string, float>
     */
    public static function fairProbabilities(array $americanBySide, VigMethod $method = VigMethod::Multiplicative): array
    {
        if (count($americanBySide) < 2) {
            throw new InvalidArgumentException('Removing vig needs at least two sides of a market.');
        }

        $implied = [];

        foreach ($americanBySide as $side => $american) {
            $implied[$side] = OddsConverter::americanToProbability($american);
        }

        return match ($method) {
            VigMethod::Multiplicative => self::multiplicative($implied),
            VigMethod::Power => self::power($implied),
        };
    }

    /**
     * The overround: how much more than 100 percent the book is charging.
     * 0.0476 on a standard -110 two-way market.
     *
     * @param  array<string, int>  $americanBySide
     */
    public static function overround(array $americanBySide): float
    {
        $sum = 0.0;

        foreach ($americanBySide as $american) {
            $sum += OddsConverter::americanToProbability($american);
        }

        return $sum - 1.0;
    }

    /** The share of every dollar wagered the book keeps if action is balanced. */
    public static function holdPercentage(array $americanBySide): float
    {
        $overround = self::overround($americanBySide);

        return $overround / (1.0 + $overround);
    }

    /**
     * @param  array<string, float>  $implied
     * @return array<string, float>
     */
    private static function multiplicative(array $implied): array
    {
        $total = array_sum($implied);

        if ($total <= 0.0) {
            throw new InvalidArgumentException('Implied probabilities must be positive.');
        }

        return array_map(static fn (float $p) => $p / $total, $implied);
    }

    /**
     * Solve sum(p_i ^ k) = 1 for k by bisection.
     *
     * k is above 1 for a normal overround market. Bisection rather than
     * Newton because the function is monotone in k and bisection cannot
     * diverge, which matters when this runs unattended on every market.
     *
     * @param  array<string, float>  $implied
     * @return array<string, float>
     */
    private static function power(array $implied): array
    {
        $sumAt = static function (float $k) use ($implied): float {
            $total = 0.0;

            foreach ($implied as $p) {
                $total += $p ** $k;
            }

            return $total;
        };

        $low = 0.5;
        $high = 5.0;

        // An underround market (sum below one, i.e. arbitrage) needs k below
        // 1, so widen downward rather than failing.
        while ($sumAt($low) < 1.0 && $low > 0.01) {
            $low /= 2;
        }

        for ($i = 0; $i < 200; $i++) {
            $mid = ($low + $high) / 2;
            $value = $sumAt($mid);

            if (abs($value - 1.0) < 1e-12) {
                break;
            }

            // Raising k lowers every probability below 1, so the sum falls.
            if ($value > 1.0) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        $k = ($low + $high) / 2;
        $fair = [];

        foreach ($implied as $side => $p) {
            $fair[$side] = $p ** $k;
        }

        // Normalise away the last of the bisection residue.
        $total = array_sum($fair);

        return array_map(static fn (float $p) => $p / $total, $fair);
    }
}
