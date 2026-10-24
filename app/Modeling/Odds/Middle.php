<?php

declare(strict_types=1);

namespace App\Modeling\Odds;

use App\Enums\Market;
use InvalidArgumentException;

/**
 * Two sides of a spread or total taken at different numbers, leaving a gap
 * of results in which both bets win.
 *
 * A middle is an arbitrage that did not quite come off. Take the same split
 * of stake, and if the result misses the gap one leg wins and the other
 * loses, costing the overround; if it lands inside, both legs win. So the
 * whole proposition reduces to one comparison: how often the gap has to be
 * hit against how often the market thinks it will be.
 *
 * That break-even rate falls out with no algebra worth the name. Stake the
 * two legs for equal return, let S be the implied sum of the two prices
 * taken, and the return on a miss is 1/S of the stake while a hit returns
 * 2/S. Setting the expectation to zero gives
 *
 *     p = S - 1
 *
 * the overround itself. Two legs at -110 need the middle 4.76% of the time,
 * which is why middles are mostly a way of paying the vig twice for a
 * lottery ticket, and why this class reports the break-even rate beside the
 * market's own estimate rather than reporting a width and letting the width
 * look like an opportunity.
 *
 * And that comparison has a fixed answer, which is the single most useful
 * thing to know about middles: **the board can never price one as worth
 * taking.** To middle a market you have to buy the low leg at the book
 * showing the low number and the high leg at the book showing the high one,
 * and each of those is the favoured side of its own market - that is what
 * makes the gap a gap. So the chance of the gap landing is already inside
 * both prices, and each book's margin is charged on top of it. The market
 * hit probability therefore sits below the break-even rate for every pair
 * of real prices, under either vig method, and MiddleTest holds that as an
 * invariant over thousands of random boards.
 *
 * The one exception is when the two legs taken already pay either way, at
 * which point the thing is an arbitrage with a gap attached and belongs to
 * Arbitrage rather than here.
 *
 * What follows is the whole justification for the feature: a middle is
 * worth taking only when a *model* says the result lands in the gap more
 * often than the price implies. Width never establishes that, and neither
 * does the board. So the finder reports both numbers and lets them
 * disagree, and a positive verdict can only ever come from the model.
 */
final class Middle
{
    /**
     * The results in which both legs win, as a half-open interval of the
     * quantity being bet: the home margin for a spread, the combined score
     * for a total.
     *
     * For a total it is simply over-line to under-line. For a spread, a home
     * line of -2.5 wins above a margin of 2.5 and an away line of +3.5 wins
     * below 3.5, so the gap is 2.5 to 3.5 and a 3-point home win takes both.
     *
     * Returns null when the lines do not actually leave a gap, which is the
     * ordinary case: a market quoted at the same number everywhere has no
     * middle in it.
     *
     * @return array{low: float, high: float}|null
     */
    public static function window(Market $market, float $lowLegLine, float $highLegLine): ?array
    {
        [$low, $high] = match ($market) {
            // Home line -2.5 wins above 2.5; away line +3.5 wins below 3.5.
            Market::Spread => [-$lowLegLine, $highLegLine],
            Market::Total => [$lowLegLine, $highLegLine],
            Market::Moneyline => throw new InvalidArgumentException(
                'A moneyline has no line to middle. Two moneylines priced apart are an arbitrage or nothing.'
            ),
        };

        if ($high <= $low) {
            return null;
        }

        return ['low' => $low, 'high' => $high];
    }

    public static function width(Market $market, float $lowLegLine, float $highLegLine): float
    {
        $window = self::window($market, $lowLegLine, $highLegLine);

        return $window === null ? 0.0 : $window['high'] - $window['low'];
    }

    /**
     * Whole-number results strictly inside the gap - the "outs", which is
     * how a middle is actually counted. A 44/47.5 total has three: 45, 46
     * and 47.
     *
     * Strictly inside, because a result landing exactly on a line pushes
     * that leg rather than winning it. A push is a better outcome than a
     * miss and a worse one than a hit, and folding it into either would
     * misstate the bet, so it is left out of the count and out of the
     * break-even arithmetic.
     */
    public static function outs(Market $market, float $lowLegLine, float $highLegLine): int
    {
        $window = self::window($market, $lowLegLine, $highLegLine);

        if ($window === null) {
            return 0;
        }

        $first = (int) floor($window['low']) + 1;
        $last = (int) ceil($window['high']) - 1;

        return max(0, $last - $first + 1);
    }

    /**
     * The two prices taken, summed as implied probabilities. Above 1.0 - as
     * it nearly always is - this is what the middle costs on a miss, and
     * also exactly the hit rate it needs.
     */
    public static function impliedSum(int $lowLegAmerican, int $highLegAmerican): float
    {
        return OddsConverter::americanToProbability($lowLegAmerican)
            + OddsConverter::americanToProbability($highLegAmerican);
    }

    /**
     * The hit rate at which the middle breaks even, which is the overround
     * on the two prices taken. See the class note for the derivation.
     */
    public static function breakEvenHitProbability(int $lowLegAmerican, int $highLegAmerican): float
    {
        return self::impliedSum($lowLegAmerican, $highLegAmerican) - 1.0;
    }

    /**
     * Net result per 1 unit of total stake when the gap is missed: one leg
     * wins, the other loses. Negative in every ordinary case.
     */
    public static function missPerUnit(int $lowLegAmerican, int $highLegAmerican): float
    {
        return (1.0 / self::impliedSum($lowLegAmerican, $highLegAmerican)) - 1.0;
    }

    /** Net result per 1 unit of total stake when both legs win. */
    public static function hitPerUnit(int $lowLegAmerican, int $highLegAmerican): float
    {
        return (2.0 / self::impliedSum($lowLegAmerican, $highLegAmerican)) - 1.0;
    }

    /**
     * Expected value per 1 unit of total stake at a given hit rate. Positive
     * only when that rate beats the overround.
     */
    public static function expectedValuePerUnit(int $lowLegAmerican, int $highLegAmerican, float $hitProbability): float
    {
        if ($hitProbability < 0.0 || $hitProbability > 1.0) {
            throw new InvalidArgumentException("Hit probability must be between 0 and 1, got {$hitProbability}.");
        }

        return ($hitProbability * self::hitPerUnit($lowLegAmerican, $highLegAmerican))
            + ((1.0 - $hitProbability) * self::missPerUnit($lowLegAmerican, $highLegAmerican));
    }

    /**
     * The market's own estimate of how often the gap lands, from the two
     * no-vig probabilities of the legs winning.
     *
     * The two legs between them cover every result, and overlap on exactly
     * the middle, so the overlap is what the two probabilities carry above
     * one. No model is consulted and none is needed: this is the price of
     * the gap, taken from the same board the legs came from.
     */
    public static function marketHitProbability(float $lowLegFairProb, float $highLegFairProb): float
    {
        return max(0.0, $lowLegFairProb + $highLegFairProb - 1.0);
    }

    /**
     * Stake split for equal return whichever single leg wins, so that a miss
     * costs the same either way. Identical in form to the arbitrage split,
     * because a middle is the same bet with the sign of the margin flipped.
     *
     * @return array{low: int, high: int}
     */
    public static function stakeSplitCents(int $lowLegAmerican, int $highLegAmerican, int $totalCents): array
    {
        $split = Arbitrage::stakeSplitCents(
            ['low' => $lowLegAmerican, 'high' => $highLegAmerican],
            $totalCents,
        );

        return ['low' => $split['low'], 'high' => $split['high']];
    }
}
