<?php

declare(strict_types=1);

namespace App\Modeling\Odds;

/**
 * Parlay arithmetic.
 *
 * A parlay is not a different kind of bet, it is the same bet several times
 * with the margin compounded, and that is the one thing a tool like this
 * owes its owner before anything else. A four-leg parlay out of legs the
 * book holds four and a half percent on is not a four and a half percent
 * proposition: it is closer to seventeen. Every method here exists to put
 * that number on the screen rather than the payout multiple.
 *
 * Two rules the maths cannot enforce on its own, so callers must:
 *
 *  - Multiplying leg prices only gives the real price where the book
 *    actually prices a parlay that way. For legs in the same game it does
 *    not, because the legs are correlated and the book knows it. Nothing
 *    here may be presented as an executable same-game price.
 *  - Multiplying probabilities assumes independence. That is a reasonable
 *    approximation across different games and plainly false inside one.
 *    jointProbability() therefore refuses to answer unless the caller has
 *    established that the legs are independent.
 */
final class Parlay
{
    /**
     * The combined decimal price of independent legs.
     *
     * @param  array<int, int>  $americanPrices
     */
    public static function decimalPrice(array $americanPrices): float
    {
        $decimal = 1.0;

        foreach ($americanPrices as $american) {
            $decimal *= OddsConverter::americanToDecimal($american);
        }

        return $decimal;
    }

    /**
     * The combined American price, for comparing against what a book shows.
     *
     * @param  array<int, int>  $americanPrices
     */
    public static function americanPrice(array $americanPrices): int
    {
        return OddsConverter::decimalToAmerican(self::decimalPrice($americanPrices));
    }

    /**
     * The chance every leg lands, assuming the legs are independent.
     *
     * @param  array<int, float>  $probabilities
     */
    public static function jointProbability(array $probabilities): float
    {
        $joint = 1.0;

        foreach ($probabilities as $probability) {
            $joint *= max(0.0, min(1.0, $probability));
        }

        return $joint;
    }

    /**
     * What the book keeps, as a share of the stake.
     *
     * This is the number the whole screen is built around. For one bet it
     * is roughly the hold on that market; across legs it compounds, because
     * each leg's margin is applied to the payout of the one before it.
     *
     * Worked from the fair probability of each leg and the price actually
     * offered: 1 - product of (fair probability x decimal price). A hold of
     * zero would mean a book giving its margin away.
     *
     * @param  array<int, float>  $fairProbabilities  per leg, vig removed
     * @param  array<int, int>  $americanPrices  per leg, as offered
     */
    public static function hold(array $fairProbabilities, array $americanPrices): ?float
    {
        if (count($fairProbabilities) !== count($americanPrices) || $fairProbabilities === []) {
            return null;
        }

        $kept = 1.0;

        foreach (array_values($fairProbabilities) as $index => $fair) {
            if ($fair <= 0.0 || $fair >= 1.0) {
                return null;
            }

            $kept *= $fair * OddsConverter::americanToDecimal(array_values($americanPrices)[$index]);
        }

        return 1.0 - $kept;
    }

    /**
     * The hold a single leg of the same quality would carry, for contrast.
     *
     * Printed beside the parlay's own hold, because the point is not that a
     * parlay has a margin - everything does - but how much faster it grows
     * with each leg added.
     *
     * @param  array<int, float>  $fairProbabilities
     * @param  array<int, int>  $americanPrices
     */
    public static function holdPerLeg(array $fairProbabilities, array $americanPrices): ?float
    {
        $hold = self::hold($fairProbabilities, $americanPrices);

        if ($hold === null) {
            return null;
        }

        // The per-leg margin that compounds to the parlay's, which is the
        // honest way to express "this is like betting n times at m percent".
        return 1.0 - ((1.0 - $hold) ** (1 / count($fairProbabilities)));
    }

    /**
     * Expected value per unit staked, at the price on offer.
     *
     * Positive here is not a profit. It is a statement about a model's
     * probability, and a parlay multiplies whatever that model is wrong
     * about rather than averaging it away.
     */
    public static function expectedValue(float $jointProbability, float $decimalPrice): float
    {
        return ($jointProbability * $decimalPrice) - 1.0;
    }

    /** The win rate a parlay has to clear at this price just to break even. */
    public static function breakEven(float $decimalPrice): float
    {
        return $decimalPrice <= 1.0 ? 1.0 : 1.0 / $decimalPrice;
    }

    /**
     * How much worse a model's error gets with each leg.
     *
     * If every leg's probability is overstated by the same small amount,
     * the joint probability is overstated by roughly that amount times the
     * number of legs. This returns the joint probability recomputed with
     * each leg shaded by one margin of error, which is the only responsible
     * way to read a parlay EV: if the edge does not survive it, there was
     * never an edge, only confidence.
     *
     * @param  array<int, float>  $probabilities
     */
    public static function shaded(array $probabilities, float $shade): float
    {
        return self::jointProbability(array_map(
            static fn (float $p) => max(0.0, $p - $shade),
            $probabilities,
        ));
    }
}
