<?php

declare(strict_types=1);

namespace App\Modeling\Ratings;

use App\Data\BacktestGame;
use App\Data\PreGameContext;

/**
 * Carries a whole league of Elo ratings through a season and across seasons.
 *
 * This is the object the walk-forward runner drives: ask it for a
 * probability, then show it the result. It is deliberately the only place
 * that knows ratings change, so the runner stays a pure replay loop and the
 * rater stays pure arithmetic.
 */
final class EloLeague
{
    /** @var array<int, float> */
    private array $ratings = [];

    private ?int $currentSeason = null;

    private int $gamesLearned = 0;

    public function __construct(
        private readonly EloRater $rater,
        /** How far ratings are pulled back to the mean at a season boundary. */
        private readonly float $seasonRegression = 0.33,
    ) {}

    public function ratingFor(int $teamId): float
    {
        return $this->ratings[$teamId] ?? EloRater::DEFAULT_RATING;
    }

    /** Home win probability for a game that has not been played. */
    public function probability(PreGameContext $context): float
    {
        return $this->rater->winProbability(
            $this->ratingFor($context->homeTeamId),
            $this->ratingFor($context->awayTeamId),
            $context->neutralSite,
        );
    }

    /**
     * Learn from a finished game.
     *
     * A new season regresses every rating toward the mean first. Rosters
     * turn over, and carrying last year forward untouched is one of the
     * easiest ways to look sharp in a backtest and be wrong in week one.
     */
    public function learn(BacktestGame $game): void
    {
        if ($game->season !== null && $this->currentSeason !== null && $game->season !== $this->currentSeason) {
            $this->regressSeason();
        }

        if ($game->season !== null) {
            $this->currentSeason = $game->season;
        }

        $result = $this->rater->update(
            $this->ratingFor($game->homeTeamId),
            $this->ratingFor($game->awayTeamId),
            $game->homeScore,
            $game->awayScore,
            $game->neutralSite,
        );

        $this->ratings[$game->homeTeamId] = $result['home'];
        $this->ratings[$game->awayTeamId] = $result['away'];
        $this->gamesLearned++;
    }

    public function regressSeason(): void
    {
        foreach ($this->ratings as $teamId => $rating) {
            $this->ratings[$teamId] = $this->rater->regressToMean($rating, $this->seasonRegression);
        }
    }

    /** @return array<int, float> */
    public function ratings(): array
    {
        return $this->ratings;
    }

    public function gamesLearned(): int
    {
        return $this->gamesLearned;
    }

    /** Everything needed to rebuild this league exactly, for storage. */
    public function toParams(): array
    {
        return [
            'rater' => $this->rater->params(),
            'season_regression' => $this->seasonRegression,
            'ratings' => $this->ratings,
            'current_season' => $this->currentSeason,
            'games_learned' => $this->gamesLearned,
        ];
    }

    /** @param  array<string, mixed>  $params */
    public static function fromParams(array $params): self
    {
        $league = new self(
            EloRater::fromParams((array) ($params['rater'] ?? [])),
            (float) ($params['season_regression'] ?? 0.33),
        );

        foreach ((array) ($params['ratings'] ?? []) as $teamId => $rating) {
            $league->ratings[(int) $teamId] = (float) $rating;
        }

        $league->currentSeason = $params['current_season'] ?? null;
        $league->gamesLearned = (int) ($params['games_learned'] ?? 0);

        return $league;
    }
}
