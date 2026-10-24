<?php

declare(strict_types=1);

namespace App\Modeling\Features;

use App\Data\BacktestGame;
use App\Data\PreGameContext;

/**
 * Turns a scheduled game into a feature vector.
 *
 * Form is the dangerous half of this class, because "how well has this team
 * been playing" is one subtraction away from "did this team win". Two rules
 * keep it honest:
 *
 *  - the rolling record is only ever updated through learn(), which the
 *    walk-forward runner calls after the prediction has been scored;
 *  - build() takes a PreGameContext, which carries no score, so it cannot
 *    reach the result even by accident.
 *
 * Form is also centred on zero rather than on a win rate, so a team with no
 * history looks average instead of looking terrible. With a blank slate
 * every form feature is 0.0, which is exactly what "no opinion" should mean
 * to a linear model.
 */
final class FeatureBuilder
{
    /** @var array<int, array<int, array{won: float, margin: int}>> */
    private array $history = [];

    public function __construct(
        /** How many recent games count as form. */
        private readonly int $formWindow = 10,
        /** Games a team needs before its form is trusted at all. */
        private readonly int $minimumHistory = 4,
    ) {}

    /**
     * @return array<string, float>
     */
    public function build(PreGameContext $context, float $eloProbability): array
    {
        $sigma = (float) ($context->sport->config('margin_sigma') ?? 13.5);

        return [
            'elo_logit' => $this->logit($eloProbability),

            'rest_diff' => $this->restDiff($context),
            'home_short_week' => $this->shortWeek($context->homeRestDays),
            'away_short_week' => $this->shortWeek($context->awayRestDays),

            'indoors' => $this->indoors($context->roof),
            'retractable' => $this->retractable($context->roof),
            'divisional' => $context->divisional === true ? 1.0 : 0.0,

            'home_form' => $this->form($context->homeTeamId),
            'away_form' => $this->form($context->awayTeamId),
            'home_margin_form' => $this->marginForm($context->homeTeamId, $sigma),
            'away_margin_form' => $this->marginForm($context->awayTeamId, $sigma),
        ];
    }

    /** @return array<int, float> */
    public function vector(PreGameContext $context, float $eloProbability): array
    {
        return FeatureSchema::vector($this->build($context, $eloProbability));
    }

    /**
     * Record a finished game. Called only after the prediction for it has
     * already been made and scored.
     */
    public function learn(BacktestGame $game): void
    {
        $margin = $game->margin();

        // A tie counts as half a win to both sides, the same convention Elo
        // uses, so the two stay consistent with each other.
        $homeResult = $game->wasTie() ? 0.5 : ($margin > 0 ? 1.0 : 0.0);

        $this->push($game->homeTeamId, $homeResult, $margin);
        $this->push($game->awayTeamId, 1.0 - $homeResult, -$margin);
    }

    public function gamesFor(int $teamId): int
    {
        return count($this->history[$teamId] ?? []);
    }

    /**
     * Everything needed to rebuild this builder exactly, for storage.
     *
     * The rolling form window is state, not configuration: a model restored
     * without it would price the first game of the day as though every team
     * had just arrived in the league. Storing it is what makes a saved model
     * version a complete model rather than a set of weights.
     */
    public function toParams(): array
    {
        return [
            'form_window' => $this->formWindow,
            'minimum_history' => $this->minimumHistory,
            'history' => $this->history,
        ];
    }

    /** @param  array<string, mixed>  $params */
    public static function fromParams(array $params): self
    {
        $builder = new self(
            formWindow: (int) ($params['form_window'] ?? 10),
            minimumHistory: (int) ($params['minimum_history'] ?? 4),
        );

        foreach ((array) ($params['history'] ?? []) as $teamId => $games) {
            foreach ((array) $games as $game) {
                $builder->history[(int) $teamId][] = [
                    'won' => (float) ($game['won'] ?? 0.0),
                    'margin' => (int) ($game['margin'] ?? 0),
                ];
            }
        }

        return $builder;
    }

    private function push(int $teamId, float $won, int $margin): void
    {
        $this->history[$teamId][] = ['won' => $won, 'margin' => $margin];

        if (count($this->history[$teamId]) > $this->formWindow) {
            array_shift($this->history[$teamId]);
        }
    }

    /**
     * Win rate over the window, centred so that a .500 team and a team with
     * no record both read as 0.0.
     */
    private function form(int $teamId): float
    {
        $games = $this->history[$teamId] ?? [];

        if (count($games) < $this->minimumHistory) {
            return 0.0;
        }

        $wins = 0.0;

        foreach ($games as $game) {
            $wins += $game['won'];
        }

        return ($wins / count($games)) - 0.5;
    }

    /**
     * Average points margin over the window, divided by the sport's typical
     * margin spread so the number lands near [-1, 1] in every sport instead
     * of meaning something different in basketball than in hockey.
     */
    private function marginForm(int $teamId, float $sigma): float
    {
        $games = $this->history[$teamId] ?? [];

        if (count($games) < $this->minimumHistory || $sigma <= 0.0) {
            return 0.0;
        }

        $total = 0;

        foreach ($games as $game) {
            $total += $game['margin'];
        }

        return ($total / count($games)) / $sigma;
    }

    private function restDiff(PreGameContext $context): float
    {
        if ($context->homeRestDays === null || $context->awayRestDays === null) {
            return 0.0;
        }

        // A bye gives 13 or 14 days and anything beyond that is the gap
        // between seasons, which says nothing about readiness.
        $home = min(14, max(0, $context->homeRestDays));
        $away = min(14, max(0, $context->awayRestDays));

        return (float) ($home - $away);
    }

    private function shortWeek(?int $restDays): float
    {
        if ($restDays === null) {
            return 0.0;
        }

        return $restDays <= 5 && $restDays >= 0 ? 1.0 : 0.0;
    }

    /**
     * Whether the game is played under a permanent roof.
     *
     * Only a fixed dome counts, and this is the subtlest judgement in the
     * class. The source also reports "closed" and "open" for the six
     * stadiums with retractable roofs, and it is tempting to fold "closed"
     * in here - those games really are played indoors.
     *
     * It would also be leakage of the quietest kind. Whether the roof is
     * shut is decided on the day: Atlanta's is closed for 39 of its games in
     * this history and open for 10. The source fills the column in
     * afterwards, so a backtest sees it for every game, while a model
     * pricing Sunday's game on Thursday cannot know it. The feature would
     * train on information that simply is not there when it is time to bet,
     * and the backtest would never say so.
     *
     * So a fixed dome is indoors, and a retractable roof gets its own flag
     * below. Both are properties of the venue, true the moment the schedule
     * is published, which is the only standard a feature has to meet.
     */
    private function indoors(?string $roof): float
    {
        if ($roof === null) {
            return 0.0;
        }

        return match (strtolower(trim($roof))) {
            'dome', 'indoors', 'indoor' => 1.0,
            default => 0.0,
        };
    }

    /**
     * Whether the venue has a roof that can be shut, without claiming to
     * know whether it was. A venue constant, and knowable in advance.
     */
    private function retractable(?string $roof): float
    {
        if ($roof === null) {
            return 0.0;
        }

        return match (strtolower(trim($roof))) {
            'closed', 'open', 'retractable' => 1.0,
            default => 0.0,
        };
    }

    /**
     * Log odds, clamped. An Elo probability of exactly 0 or 1 is impossible
     * in practice but a single infinity would poison the whole fit, so the
     * bound is cheap insurance.
     */
    private function logit(float $probability): float
    {
        $p = min(1 - 1e-6, max(1e-6, $probability));

        return log($p / (1 - $p));
    }
}
