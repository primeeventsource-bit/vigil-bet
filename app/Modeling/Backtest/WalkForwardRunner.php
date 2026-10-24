<?php

declare(strict_types=1);

namespace App\Modeling\Backtest;

use App\Data\BacktestGame;
use App\Data\PreGameContext;
use App\Enums\Market;
use App\Modeling\Math\Normal;
use InvalidArgumentException;

/**
 * Walk-forward backtesting.
 *
 * Games are replayed in the order they were played. For each one the model
 * predicts first, using only what it learned from earlier games, and only
 * then is it shown the result. Nothing is ever trained on a game it has
 * already been scored on.
 *
 * This ordering is the whole guarantee, and it is enforced three ways:
 *
 *  - the predictor is handed a PreGameContext, which has no score on it;
 *  - the runner asserts games arrive in chronological order, so a shuffled
 *    or badly sorted query fails loudly instead of quietly leaking;
 *  - a burn-in period is scored as nothing, because the first few hundred
 *    games are the model learning what a team is.
 *
 * A model that scores well in here has not seen the future. That is a much
 * stronger claim than a train/test split, where a single careless join can
 * put next week in this week.
 */
final class WalkForwardRunner
{
    public function __construct(
        /** Games replayed before scoring starts, while ratings settle. */
        private readonly int $burnIn = 200,
        /**
         * The market being scored.
         *
         * The model always predicts the same thing - who wins - and the
         * market decides what question that answer is held to. Getting
         * this wrong does not fail: asking "did the home side win" of a
         * spread returns a number, and a flattering one, because a spread
         * closes near even money and a moneyline-accurate model beats a
         * coin flip easily on a question the market was never pricing.
         */
        private readonly Market $market = Market::Moneyline,
        /**
         * The sport's margin spread, used to restate a win probability as
         * a cover probability.
         *
         * Carried rather than looked up so this class stays free of
         * config, and so a test can set it.
         */
        private readonly float $sigma = 13.5,
    ) {}

    /**
     * @param  iterable<int, BacktestGame>  $games  chronological
     * @param  callable(PreGameContext): ?float  $predict  home win probability
     * @param  callable(BacktestGame): void  $learn  shown the result afterwards
     * @return array<int, BacktestPrediction>
     */
    public function run(iterable $games, callable $predict, callable $learn): array
    {
        $predictions = [];
        $seen = 0;
        $previousStart = null;

        foreach ($games as $game) {
            if ($previousStart !== null && $game->startsAt->lessThan($previousStart)) {
                throw new InvalidArgumentException(
                    'Backtest games must arrive in chronological order; '
                    ."event {$game->eventId} starts before the game before it."
                );
            }

            $previousStart = $game->startsAt;
            $seen++;

            // Predict first, from a view that cannot see the result.
            if ($seen > $this->burnIn) {
                $probability = $predict($game->context());

                if ($probability !== null) {
                    $scored = $this->score($game, $probability);

                    if ($scored !== null) {
                        [$probability, $outcome] = $scored;

                        $predictions[] = new BacktestPrediction(
                            eventId: $game->eventId,
                            startsAt: $game->startsAt,
                            probability: $probability,
                            outcome: $outcome,
                            wasTie: $game->wasTie(),
                            closingMarket: $game->closingMarket,
                        );
                    }
                }
            }

            // Only now does the model get to see what happened.
            $learn($game);
        }

        return $predictions;
    }

    public function burnIn(): int
    {
        return $this->burnIn;
    }

    /**
     * The probability and the outcome, both asking the market's question.
     *
     * Returns null when this game cannot be scored for this market, which
     * is not a failure: a spread with no closing line has nothing to cover,
     * and a push is void rather than lost.
     *
     * The spread probability is derived from the win probability, not
     * separately modelled. Under a normal margin the two are the same
     * statement: a win probability p implies an expected margin of
     * sigma * inverseCdf(p), and the chance of covering is the chance that
     * margin lands beyond the line. Forecaster already restates the board
     * this way, so the backtest measures what the board would actually
     * have offered rather than a quantity nothing serves.
     *
     * That makes this partly a test of sigma, which is a per-sport
     * constant nobody has validated. A negative verdict here does not
     * distinguish a bad rating from a bad spread, and should not be read
     * as though it did.
     *
     * @return array{0: float, 1: int}|null
     */
    private function score(BacktestGame $game, float $probability): ?array
    {
        if ($this->market !== Market::Spread) {
            return [$probability, $game->homeWon()];
        }

        $line = $game->closingLine;

        if ($line === null) {
            return null;
        }

        $outcome = $game->homeCovered($line);

        if ($outcome === null) {
            return null;
        }

        $expectedMargin = $this->sigma * Normal::inverseCdf($probability);

        return [Normal::survival(-$line, $expectedMargin, $this->sigma), $outcome];
    }
}
