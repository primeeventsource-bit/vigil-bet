<?php

declare(strict_types=1);

namespace App\Services\Modeling;

use App\Data\BacktestGame;
use App\Enums\Market;
use App\Enums\ModelType;
use App\Enums\Sport;
use App\Exceptions\UnmodelledMarket;
use App\Modeling\Backtest\BacktestEvaluator;
use App\Modeling\Backtest\BacktestPrediction;
use App\Modeling\Backtest\BacktestSummary;
use App\Modeling\Backtest\WalkForwardRunner;
use App\Modeling\Features\FeatureBuilder;
use App\Modeling\Features\FeatureSchema;
use App\Modeling\Predictors\LogisticPredictor;
use App\Modeling\Ratings\EloLeague;
use App\Modeling\Ratings\EloRater;
use App\Models\BacktestRun;
use App\Models\CalibrationBin;

/**
 * Runs a walk-forward backtest for one sport and market, and records what it
 * found.
 *
 * The tuning rule is the important part. Sweeping K over the same games the
 * result is then reported on is how a backtest becomes a sales pitch: with
 * enough parameters something always looks good. So when tuning is asked
 * for, the history is split - the early portion picks the parameter, and the
 * metrics that reach the verdict come only from the later portion the sweep
 * never saw.
 */
class BacktestService
{
    /** K factors worth trying. Wide enough to matter, small enough to stay honest. */
    private const K_CANDIDATES = [8.0, 12.0, 16.0, 20.0, 24.0, 32.0];

    public function __construct(
        private readonly TrainingSetBuilder $builder,
        private readonly BacktestEvaluator $evaluator,
    ) {}

    /**
     * @return array{run: BacktestRun, summary: BacktestSummary, params: array<string, mixed>}
     */
    public function run(
        Sport $sport,
        Market $market = Market::Moneyline,
        bool $tune = false,
        int $burnIn = 200,
        float $tuningShare = 0.5,
        ModelType $model = ModelType::Elo,
    ): array {
        // Totals only. Nothing here forecasts how many points get scored -
        // the projected total is a league constant - so a verdict for that
        // market would be measuring a number nobody modelled. Spreads are
        // fine: a margin is a restatement of a win probability, and the
        // runner scores them against whether the line was covered.
        if ($market === Market::Total) {
            throw UnmodelledMarket::for($market);
        }

        $games = iterator_to_array($this->builder->games($sport, market: $market), false);
        $total = count($games);

        $params = [
            'model' => $model->value,
            'k_factor' => 20.0,
            'home_advantage' => $this->defaultHomeAdvantage($sport),
            'season_regression' => 0.33,
            'tuned' => false,
        ];

        if ($model === ModelType::Logistic) {
            $params['feature_version'] = FeatureSchema::VERSION;
            $params['features'] = FeatureSchema::names();
        }

        $scored = $games;

        if ($tune && $total > ($burnIn * 3)) {
            $split = (int) floor($total * $tuningShare);
            $tuningGames = array_slice($games, 0, $split);

            // The sweep only ever sees the early half.
            $params['k_factor'] = $this->bestKFactor($tuningGames, $params, $burnIn, $market, $sport);
            $params['tuned'] = true;
            $params['tuning_games'] = $split;

            // And the reported result only ever sees the later half, warmed
            // up by replaying the early half without scoring it.
            $scored = $games;
            $burnIn = $split;
        }

        [$summary, $fitted, $league, $predictions] = $this->evaluate($scored, $params, $burnIn, $model, $market, $sport);
        $verdict = $summary->verdict();

        if ($fitted !== null) {
            $params['coefficients'] = $fitted->coefficients();
            $params['training_games'] = $fitted->trainingSize();
            $params['refits'] = $fitted->refits();
        }

        $run = BacktestRun::create([
            'sport' => $sport->value,
            'market' => $market->value,
            'model_type' => $model->value,
            'config' => $params + ['burn_in' => $burnIn],
            'from_date' => $games !== [] ? $games[0]->startsAt->toDateString() : now()->toDateString(),
            'to_date' => $games !== [] ? end($games)->startsAt->toDateString() : now()->toDateString(),
            'status' => 'complete',
            'started_at' => now(),
            'finished_at' => now(),
            'n_predictions' => $summary->predictions,
            'metrics' => $summary->toArray(),
            'market_metrics' => [
                'log_loss' => $summary->marketLogLoss,
                'brier' => $summary->marketBrier,
                'calibration_error' => $summary->marketCalibrationError,
                'with_market' => $summary->withMarket,
            ],
            'delta_log_loss' => $summary->logLossImprovement(),
            'mean_home_bias' => $summary->meanHomeBias,
            'verdict' => $verdict->verdict->value,
        ]);

        $this->storeCalibration($run->id, $summary);

        return [
            'run' => $run,
            'summary' => $summary,
            'params' => $params,
            'verdict' => $verdict,
            // The replay that produced these metrics also left behind a
            // model trained on every game in order, and the out-of-sample
            // predictions it was scored on. Both are what ModelTrainer
            // stores, so a saved version is the thing that was measured
            // rather than a second fit that merely resembles it.
            'predictions' => $predictions,
            'league' => $league,
            'predictor' => $fitted,
        ];
    }

    /**
     * @param  array<int, BacktestGame>  $games
     * @param  array<string, mixed>  $params
     * @return array{0: BacktestSummary, 1: LogisticPredictor|null, 2: EloLeague, 3: array<int, BacktestPrediction>}
     */
    private function evaluate(
        array $games,
        array $params,
        int $burnIn,
        ModelType $model = ModelType::Elo,
        Market $market = Market::Moneyline,
        ?Sport $sport = null,
    ): array {
        $sigma = (float) ($sport?->config('margin_sigma') ?? 13.5);

        $league = new EloLeague(
            new EloRater(
                kFactor: (float) $params['k_factor'],
                homeAdvantage: (float) $params['home_advantage'],
            ),
            (float) $params['season_regression'],
        );

        if ($model === ModelType::Logistic) {
            $predictor = new LogisticPredictor($league, new FeatureBuilder);

            $predictions = (new WalkForwardRunner($burnIn, $market, $sigma))->run(
                $games,
                fn ($context) => $predictor->probability($context),
                fn (BacktestGame $game) => $predictor->learn($game),
            );

            return [$this->evaluator->evaluate($predictions), $predictor, $league, $predictions];
        }

        $predictions = (new WalkForwardRunner($burnIn, $market, $sigma))->run(
            $games,
            fn ($context) => $league->probability($context),
            fn (BacktestGame $game) => $league->learn($game),
        );

        return [$this->evaluator->evaluate($predictions), null, $league, $predictions];
    }

    /**
     * @param  array<int, BacktestGame>  $games
     * @param  array<string, mixed>  $params
     */
    private function bestKFactor(
        array $games,
        array $params,
        int $burnIn,
        Market $market = Market::Moneyline,
        ?Sport $sport = null,
    ): float {
        $best = 20.0;
        $bestLoss = INF;

        foreach (self::K_CANDIDATES as $k) {
            // Swept against the same question the run will be judged on.
            // A K chosen for predicting winners and then reported against
            // covering spreads is two different experiments wearing one
            // number.
            [$summary] = $this->evaluate($games, ['k_factor' => $k] + $params, $burnIn, market: $market, sport: $sport);

            if ($summary->predictions === 0) {
                continue;
            }

            if ($summary->logLoss < $bestLoss) {
                $bestLoss = $summary->logLoss;
                $best = $k;
            }
        }

        return $best;
    }

    private function defaultHomeAdvantage(Sport $sport): float
    {
        // Points of home edge, converted into rating points for this sport.
        $points = (float) ($sport->config('home_advantage_points') ?? 2.0);
        $eloPerPoint = (float) ($sport->config('elo_per_point') ?? 25.0);

        return $points * $eloPerPoint;
    }

    private function storeCalibration(int $runId, BacktestSummary $summary): void
    {
        foreach ($summary->calibrationCurve as $bucket) {
            CalibrationBin::create([
                'owner_type' => 'backtest_run',
                'owner_id' => $runId,
                'bin_index' => $bucket['bin'],
                'p_low' => $bucket['low'],
                'p_high' => $bucket['high'],
                'n' => $bucket['n'],
                'mean_predicted' => $bucket['mean_predicted'],
                'observed_rate' => $bucket['observed_rate'],
                'created_at' => now(),
            ]);
        }
    }
}
