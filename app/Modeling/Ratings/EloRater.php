<?php

declare(strict_types=1);

namespace App\Modeling\Ratings;

use InvalidArgumentException;

/**
 * Elo power ratings.
 *
 * The baseline every other model has to beat. It is deliberately simple:
 * one number per team, updated by how surprising each result was. Simple is
 * the point, because a baseline that is hard to understand is a baseline you
 * cannot tell is broken.
 *
 * Three additions over textbook Elo, each of which matters in sport:
 *
 *  - home advantage, applied as rating points rather than a probability;
 *  - a margin of victory multiplier, so a blowout moves ratings more than a
 *    last-second field goal, damped so running up the score stops helping;
 *  - regression to the mean between seasons, because rosters turn over and
 *    last year is weak evidence about this year.
 */
final class EloRater
{
    public const DEFAULT_RATING = 1500.0;

    public function __construct(
        private readonly float $kFactor = 20.0,
        private readonly float $homeAdvantage = 55.0,
        private readonly float $scale = 400.0,
        private readonly bool $useMarginOfVictory = true,
    ) {
        if ($kFactor <= 0.0) {
            throw new InvalidArgumentException("K factor must be positive, got {$kFactor}.");
        }
    }

    /**
     * Win probability for the home side, before any score is known.
     *
     * Neutral sites drop the home bonus entirely rather than halving it,
     * because a neutral site is genuinely neutral.
     */
    public function winProbability(float $homeRating, float $awayRating, bool $neutralSite = false): float
    {
        $advantage = $neutralSite ? 0.0 : $this->homeAdvantage;
        $difference = ($homeRating + $advantage) - $awayRating;

        return 1.0 / (1.0 + (10 ** (-$difference / $this->scale)));
    }

    /** The rating gap implied by a probability, used to sanity check a model. */
    public function ratingDifferenceFor(float $probability): float
    {
        if ($probability <= 0.0 || $probability >= 1.0) {
            throw new InvalidArgumentException("Probability must be strictly between 0 and 1, got {$probability}.");
        }

        return -$this->scale * log10((1.0 / $probability) - 1.0);
    }

    /**
     * New ratings after a result.
     *
     * @return array{home:float, away:float, shift:float, expected:float}
     */
    public function update(
        float $homeRating,
        float $awayRating,
        int $homeScore,
        int $awayScore,
        bool $neutralSite = false,
    ): array {
        $expected = $this->winProbability($homeRating, $awayRating, $neutralSite);

        $actual = match (true) {
            $homeScore > $awayScore => 1.0,
            $homeScore < $awayScore => 0.0,
            default => 0.5,
        };

        $multiplier = $this->useMarginOfVictory
            ? $this->marginMultiplier($homeScore - $awayScore, $homeRating + ($neutralSite ? 0.0 : $this->homeAdvantage) - $awayRating)
            : 1.0;

        $shift = $this->kFactor * $multiplier * ($actual - $expected);

        return [
            'home' => $homeRating + $shift,
            'away' => $awayRating - $shift,
            'shift' => $shift,
            'expected' => $expected,
        ];
    }

    /**
     * Margin of victory multiplier, in the form popularised by FiveThirtyEight.
     *
     * The log damps blowouts so a 50 point win is not worth five times a 10
     * point win. The denominator damps the opposite failure: a heavy
     * favourite winning big is unsurprising and should move ratings less
     * than an underdog doing the same.
     */
    public function marginMultiplier(int $margin, float $ratingDifference): float
    {
        $absoluteMargin = abs($margin);

        if ($absoluteMargin === 0) {
            return 1.0;
        }

        $winnerDifference = $margin > 0 ? $ratingDifference : -$ratingDifference;

        return log($absoluteMargin + 1.0) * (2.2 / (($winnerDifference * 0.001) + 2.2));
    }

    /**
     * Pull ratings back toward the mean between seasons.
     *
     * A third of the way back is the common choice for football; more
     * turnover means more regression. Carrying ratings over untouched is one
     * of the easiest ways to look clever in a backtest and be wrong in
     * September.
     */
    public function regressToMean(float $rating, float $amount = 0.33, float $mean = self::DEFAULT_RATING): float
    {
        if ($amount < 0.0 || $amount > 1.0) {
            throw new InvalidArgumentException("Regression amount must be between 0 and 1, got {$amount}.");
        }

        return $rating + (($mean - $rating) * $amount);
    }

    public function kFactor(): float
    {
        return $this->kFactor;
    }

    public function homeAdvantage(): float
    {
        return $this->homeAdvantage;
    }

    /** @return array{k_factor:float, home_advantage:float, scale:float, margin_of_victory:bool} */
    public function params(): array
    {
        return [
            'k_factor' => $this->kFactor,
            'home_advantage' => $this->homeAdvantage,
            'scale' => $this->scale,
            'margin_of_victory' => $this->useMarginOfVictory,
        ];
    }

    /** @param  array<string, mixed>  $params */
    public static function fromParams(array $params): self
    {
        return new self(
            kFactor: (float) ($params['k_factor'] ?? 20.0),
            homeAdvantage: (float) ($params['home_advantage'] ?? 55.0),
            scale: (float) ($params['scale'] ?? 400.0),
            useMarginOfVictory: (bool) ($params['margin_of_victory'] ?? true),
        );
    }
}
