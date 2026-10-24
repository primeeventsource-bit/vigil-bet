<?php

declare(strict_types=1);

namespace App\Modeling\Odds;

use App\Enums\VigMethod;

/**
 * Closing line value: whether a bet beat the market by the time it closed.
 *
 * This is the honest scoreboard. Results are mostly noise over any sample a
 * single person will ever place - a good bet loses constantly - but a price
 * that consistently beats the close is evidence of a real edge, and it shows
 * up in a few hundred bets instead of a few thousand.
 *
 * Measured in probability, not price. "Took -110 on something that closed at
 * -130" means nothing until it is stated as 52.4 percent against 56.5, which
 * is four points of value.
 *
 * This belongs to placed bets and nowhere else. It needs two prices - the one
 * the bet was struck at and the later close - so it cannot be computed from a
 * backtest over closing lines, where only one price exists. The quantity a
 * backtest can produce is the gap between the model's opinion and the
 * market's, which is a bias measurement; see BacktestPrediction::marketGap()
 * for why that is not this and must never be used as a gate.
 *
 * Recorded onto placed bets by ClvRecorder, which runs once the closing
 * line for the bet's own book has been frozen.
 */
final class Clv
{
    /**
     * Value against the raw closing price, vig included.
     *
     * Quick and slightly flattering: the closing price still has the hold
     * in it, so a bet can look positive here while merely matching the
     * market. Use the no-vig form below for anything that counts.
     */
    public static function versusClosingPrice(int $betAmerican, int $closingAmerican): float
    {
        return OddsConverter::americanToProbability($closingAmerican)
            - OddsConverter::americanToProbability($betAmerican);
    }

    /**
     * Value against the fair closing probability, with the margin stripped
     * out of both sides of the closing market. This is the number that
     * means something.
     *
     * @param  array<string, int>  $closingMarket  both sides, keyed by side
     */
    public static function versusFairClose(
        int $betAmerican,
        array $closingMarket,
        string $side,
        VigMethod $method = VigMethod::Multiplicative,
    ): ?float {
        if (! isset($closingMarket[$side]) || count($closingMarket) < 2) {
            return null;
        }

        $fair = VigRemover::fairProbabilities($closingMarket, $method);

        return $fair[$side] - OddsConverter::americanToProbability($betAmerican);
    }

    /**
     * The same thing as a percentage of the price, which is how most people
     * quote it: 2 percent CLV means the odds taken were 2 percent longer
     * than the close.
     */
    public static function pricePercentage(int $betAmerican, int $closingAmerican): float
    {
        $bet = OddsConverter::americanToDecimal($betAmerican);
        $close = OddsConverter::americanToDecimal($closingAmerican);

        return ($bet / $close) - 1.0;
    }

    /** Did this bet beat the close at all. */
    public static function beatTheClose(int $betAmerican, int $closingAmerican): bool
    {
        return OddsConverter::americanToDecimal($betAmerican)
            > OddsConverter::americanToDecimal($closingAmerican);
    }

    /**
     * The same value in money: what the price taken was worth on this stake,
     * judged by the market's own fair closing probability.
     *
     * At the fair closing price a bet is worth nothing by definition, so
     * whatever is left over is what the price beat the close by. A $100 bet
     * at +100 on something that closes fair at 55 percent is worth $10: the
     * market says it wins 55 times in 100 and the price pays as though it
     * wins 50.
     *
     * This is an expected value, not a profit. It is what the bet was worth
     * at the moment it was struck and it does not move when the game is
     * played - which is the whole reason to track it, because results over
     * the few hundred bets one person places say almost nothing and this
     * says something immediately.
     */
    public static function expectedValueCents(int $stakeCents, int $betAmerican, float $fairCloseProbability): int
    {
        $decimal = OddsConverter::americanToDecimal($betAmerican);

        return (int) round($stakeCents * (($fairCloseProbability * $decimal) - 1.0));
    }

    /**
     * Average CLV across many bets, in probability points.
     *
     * @param  array<int, float>  $values
     */
    public static function average(array $values): float
    {
        $values = array_filter($values, static fn ($v) => $v !== null);

        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }
}
