<?php

declare(strict_types=1);

namespace App\Modeling\Predictors;

use App\Data\GameProjection;
use App\Enums\Sport;
use App\Modeling\Ratings\EloRater;

/**
 * Turns two Elo ratings into a projected scoreline.
 *
 * The conversion is one number per sport: how many rating points are worth
 * one point on the scoreboard. In the NFL that is about 25, which is why a
 * 175 point rating gap looks like a touchdown. Hockey and baseball convert
 * far more slowly, because a goal is worth much more than a point.
 *
 * Totals come from a league baseline rather than from the ratings, because
 * Elo only ever knew about margins. A model that needs real totals has to
 * learn pace and scoring separately, which is what the feature model is for.
 * Saying so here is better than implying the baseline is a prediction.
 */
class EloProjector
{
    public function __construct(private readonly EloRater $rater) {}

    public function project(
        Sport $sport,
        float $homeRating,
        float $awayRating,
        bool $neutralSite = false,
        ?float $totalOverride = null,
    ): GameProjection {
        $eloPerPoint = (float) ($sport->config('elo_per_point') ?? 25.0);
        $baselineTotal = (float) ($totalOverride ?? $sport->config('baseline_total') ?? 44.0);

        $advantage = $neutralSite ? 0.0 : $this->rater->homeAdvantage();
        $ratingGap = ($homeRating + $advantage) - $awayRating;

        $margin = $ratingGap / $eloPerPoint;

        $homeScore = ($baselineTotal / 2) + ($margin / 2);
        $awayScore = ($baselineTotal / 2) - ($margin / 2);

        // A scoring rate cannot be negative, and a lopsided rating gap in a
        // low scoring sport can push one side below zero if left unchecked.
        $floor = $baselineTotal * 0.05;
        $homeScore = max($floor, $homeScore);
        $awayScore = max($floor, $awayScore);

        return new GameProjection(
            sport: $sport,
            homeScore: $homeScore,
            awayScore: $awayScore,
            marginSigma: $sport->config('margin_sigma') !== null
                ? (float) $sport->config('margin_sigma')
                : null,
            dispersion: $sport->config('dispersion') !== null
                ? (float) $sport->config('dispersion')
                : null,
            neutralSite: $neutralSite,
            components: [
                'home_rating' => $homeRating,
                'away_rating' => $awayRating,
                'home_advantage' => $advantage,
                'rating_gap' => $ratingGap,
                'projected_margin' => $margin,
                'baseline_total' => $baselineTotal,
            ],
        );
    }

    /**
     * The Elo win probability on its own, which is not the same as the
     * moneyline the pricer produces: this ignores ties and the shape of the
     * score distribution. Useful as a sanity check against the pricer, and
     * as the baseline model in its own right.
     */
    public function winProbability(float $homeRating, float $awayRating, bool $neutralSite = false): float
    {
        return $this->rater->winProbability($homeRating, $awayRating, $neutralSite);
    }
}
