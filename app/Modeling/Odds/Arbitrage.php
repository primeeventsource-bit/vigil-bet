<?php

declare(strict_types=1);

namespace App\Modeling\Odds;

use InvalidArgumentException;

/**
 * Both sides of one market, at two different books, priced so badly between
 * them that every result pays.
 *
 * The arithmetic is the easy half and is not where the money is lost. An
 * arbitrage is a statement that two books disagree, and books mostly
 * disagree for one of two reasons: one of them has moved on news and the
 * other has not yet, or one of the two prices is not real any more. The
 * first closes in seconds. The second was never there. That is why the
 * finder that uses this class refuses stale quotes and refuses books with
 * no account behind them - an arb you cannot place at both ends is not an
 * arb, it is a screenshot.
 *
 * What is computed here is deliberately the pessimistic version: stakes in
 * whole cents, and the profit quoted is the worst of the outcomes after
 * that rounding rather than the ideal continuous one.
 */
final class Arbitrage
{
    /**
     * The sum of implied probabilities across the sides, vig included. Below
     * 1.0 the market pays more than certainty is worth, which is the whole
     * definition of an arbitrage.
     *
     * @param  array<string, int>  $americanBySide
     */
    public static function impliedSum(array $americanBySide): float
    {
        if (count($americanBySide) < 2) {
            throw new InvalidArgumentException('An arbitrage needs at least two sides.');
        }

        $sum = 0.0;

        foreach ($americanBySide as $american) {
            $sum += OddsConverter::americanToProbability($american);
        }

        return $sum;
    }

    /** @param  array<string, int>  $americanBySide */
    public static function exists(array $americanBySide): bool
    {
        return self::impliedSum($americanBySide) < 1.0;
    }

    /**
     * Profit per 1 unit of total stake, before rounding to whole cents.
     *
     * @param  array<string, int>  $americanBySide
     */
    public static function profitPerUnit(array $americanBySide): float
    {
        return (1.0 / self::impliedSum($americanBySide)) - 1.0;
    }

    /**
     * How to split a total stake so that every side returns the same.
     *
     * Proportional to each side's implied probability, which is what makes
     * the returns equal. Whole cents do not divide that cleanly, so the
     * remainder goes a cent at a time to whichever side is currently
     * returning least: that maximises the worst outcome, which is the only
     * outcome worth optimising when the entire point is that no result
     * loses.
     *
     * @param  array<string, int>  $americanBySide
     * @return array<string, int> cents per side, summing exactly to the total
     */
    public static function stakeSplitCents(array $americanBySide, int $totalCents): array
    {
        if ($totalCents <= 0) {
            throw new InvalidArgumentException('An arbitrage stake must be positive.');
        }

        $sum = self::impliedSum($americanBySide);
        $split = [];
        $allocated = 0;

        foreach ($americanBySide as $side => $american) {
            $share = OddsConverter::americanToProbability($american) / $sum;
            $cents = (int) floor($totalCents * $share);
            $split[$side] = $cents;
            $allocated += $cents;
        }

        for ($leftover = $totalCents - $allocated; $leftover > 0; $leftover--) {
            $weakest = null;
            $worst = INF;

            foreach ($split as $side => $cents) {
                $return = $cents * OddsConverter::americanToDecimal($americanBySide[$side]);

                if ($return < $worst) {
                    $worst = $return;
                    $weakest = $side;
                }
            }

            $split[$weakest]++;
        }

        return $split;
    }

    /**
     * What comes back if the worst side wins, in whole cents. Compared
     * against the stake, this is the number that says whether the arb
     * survived being rounded into real money.
     *
     * @param  array<string, int>  $americanBySide
     * @param  array<string, int>  $stakeCents
     */
    public static function worstReturnCents(array $americanBySide, array $stakeCents): int
    {
        $worst = null;

        foreach ($stakeCents as $side => $cents) {
            $return = (int) floor($cents * OddsConverter::americanToDecimal($americanBySide[$side]));
            $worst = $worst === null ? $return : min($worst, $return);
        }

        return (int) $worst;
    }

    /**
     * Guaranteed profit in cents after the split has been rounded: the worst
     * return less everything staked. This can be negative on a thin arb at a
     * small stake, and when it is, there is no arbitrage at that size.
     *
     * @param  array<string, int>  $americanBySide
     * @param  array<string, int>  $stakeCents
     */
    public static function guaranteedProfitCents(array $americanBySide, array $stakeCents): int
    {
        return self::worstReturnCents($americanBySide, $stakeCents) - array_sum($stakeCents);
    }
}
