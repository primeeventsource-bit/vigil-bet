<?php

declare(strict_types=1);

namespace App\Services\Modeling;

use App\Enums\EventStatus;
use App\Enums\Market;
use App\Enums\ModelStatus;
use App\Enums\ModelType;
use App\Enums\Sport;
use App\Modeling\Backtest\BacktestPrediction;
use App\Modeling\Features\FeatureSchema;
use App\Modeling\Math\IsotonicRegression;
use App\Modeling\Math\Metrics;
use App\Modeling\Math\PlattScaling;
use App\Models\Event;
use App\Models\ModelVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns a backtest into a stored model version.
 *
 * Phase 2 asked for trained parameters to live as versioned JSON rows so
 * that every prediction can name the exact model that produced it. Until
 * now the backtest fitted a model, reported on it and threw it away, which
 * meant the app could describe a model it could not actually run.
 *
 * The walk-forward replay is reused rather than repeated. That run already
 * visits every game in chronological order, refitting as it goes, so the
 * model left standing at the end has been trained on all of history - and
 * the predictions it was scored on were made before each game it learned
 * from. Storing that object means the saved version is the thing that was
 * measured, not a second fit that merely resembles it.
 *
 * Nothing here decides whether a market may recommend a bet. That is the
 * verdict's job and it is recorded on the row, but it does not gate storage
 * or activation - see activate() for why those are different questions.
 */
class ModelTrainer
{
    /**
     * Out-of-sample predictions needed before a calibration map is worth
     * fitting. Below this the map would be describing noise.
     */
    private const MIN_CALIBRATION_SAMPLE = 400;

    /** Share of the out-of-sample run the calibrator is allowed to see. */
    private const CALIBRATION_FIT_SHARE = 0.6;

    public function __construct(private readonly BacktestService $backtest) {}

    /**
     * Train, measure and store one sport and market.
     *
     * @return array{version: ModelVersion|null, summary: mixed, verdict: mixed, refusal: string|null}
     */
    public function train(
        Sport $sport,
        Market $market = Market::Moneyline,
        ModelType $model = ModelType::Elo,
        bool $tune = false,
        int $burnIn = 200,
        bool $activate = true,
        ?string $label = null,
    ): array {
        $result = $this->backtest->run($sport, $market, tune: $tune, burnIn: $burnIn, model: $model);

        $summary = $result['summary'];
        $refusal = $this->refusalFor($result, $model);

        if ($refusal !== null) {
            // A version that was never measured out of sample is not a
            // model, it is a guess with a version number. Refuse to store
            // one rather than let it be activated later by something that
            // only checks for a row.
            return ['version' => null, 'summary' => $summary, 'verdict' => $result['verdict'], 'refusal' => $refusal];
        }

        $version = DB::transaction(function () use ($result, $sport, $market, $model, $label, $activate): ModelVersion {
            $stored = ModelVersion::create([
                'sport' => $sport->value,
                'market' => $market->value,
                'model_type' => $model->value,
                'version' => $this->nextVersion($sport, $market, $model),
                'label' => $label,
                'params' => $this->params($result, $model),
                'calibration' => $this->calibrate($result['predictions']),
                'feature_schema_version' => $model === ModelType::Logistic ? FeatureSchema::VERSION : null,
                'trained_at' => now(),
                'trained_through_at' => $this->trainedThrough($sport),
                'training_rows' => $this->trainingRows($result, $model),
                'holdout_metrics' => $result['summary']->toArray() + [
                    'verdict' => $result['verdict']->verdict->value,
                    'verdict_reason' => $result['verdict']->reason,
                ],
                'status' => ModelStatus::Candidate->value,
                'backtest_run_id' => $result['run']->id,
                'notes' => $result['verdict']->reason,
            ]);

            return $activate ? $this->activate($stored) : $stored;
        });

        return ['version' => $version, 'summary' => $summary, 'verdict' => $result['verdict'], 'refusal' => null];
    }

    /**
     * Make this version the one predictions are made with, and retire
     * whatever held the post before it.
     *
     * Activation goes to the newest trained version in its lineage rather
     * than to whichever version scored best. Those scores are measured on
     * different games - a later training run has seen more history - so
     * picking a winner between them would be comparing two numbers that
     * were never comparable. The metrics are on the row for the reader to
     * judge; they are not a gate here.
     *
     * The verdict is not a gate either, and that is deliberate. A model
     * that has not beaten the closing line still needs to make predictions,
     * because predictions recorded before kickoff are the only way it ever
     * earns a verdict. What the verdict governs is whether a market may turn
     * a prediction into a recommendation, which belongs to the value finder.
     */
    public function activate(ModelVersion $version): ModelVersion
    {
        ModelVersion::query()
            ->where('sport', $version->sport)
            ->where('market', $version->market)
            ->where('model_type', $version->model_type)
            ->where('status', ModelStatus::Active)
            ->whereKeyNot($version->getKey())
            ->update(['status' => ModelStatus::Retired->value, 'retired_at' => now()]);

        $version->forceFill([
            'status' => ModelStatus::Active->value,
            'activated_at' => now(),
        ])->save();

        return $version;
    }

    /**
     * Why this run cannot become a version, or null if it can.
     *
     * @param  array<string, mixed>  $result
     */
    private function refusalFor(array $result, ModelType $model): ?string
    {
        if ($result['summary']->predictions === 0) {
            return 'Nothing was scored out of sample, so there is no measurement to attach to a version. '
                .'Backfill more history or lower the burn-in.';
        }

        // Unreachable as the predictor stands today: an untrained regression
        // returns null, the runner scores nothing, and the check above
        // catches it first. It stays as the guard on that invariant, because
        // the tempting change LogisticPredictor warns against - filling the
        // untrained gap with Elo's answer - would make this the only thing
        // standing between a version row and a set of weights that does not
        // exist.
        if ($model === ModelType::Logistic && $result['predictor']?->isTrained() !== true) {
            return 'The regression never reached its minimum training size, so there are no weights to store.';
        }

        return null;
    }

    /**
     * Everything needed to rebuild this model and price a game with it.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function params(array $result, ModelType $model): array
    {
        // The backtest's own config - K, home advantage, season regression,
        // whether K was tuned - describes how the model was built, and is
        // kept beside the state so a stored version explains itself.
        $config = $result['params'];
        unset($config['coefficients']);

        if ($model === ModelType::Logistic) {
            return ['config' => $config] + $result['predictor']->toParams();
        }

        return [
            'config' => $config,
            'league' => $result['league']->toParams(),
        ];
    }

    /** @param  array<string, mixed>  $result */
    private function trainingRows(array $result, ModelType $model): int
    {
        return $model === ModelType::Logistic
            ? $result['predictor']->trainingSize()
            : $result['league']->gamesLearned();
    }

    /**
     * Fit a calibration map, and only keep it if it demonstrably helps.
     *
     * Calibration matters here more than it would elsewhere, because stake
     * sizing reads the probability literally: a model that ranks games well
     * but says 70 percent where the truth is 60 will bet too much, every
     * time, with a backtest that still looks fine on ranking metrics.
     *
     * The honesty rule is the same one the K sweep follows. A calibrator fit
     * on a set of predictions will always look better on those same
     * predictions, so the out-of-sample run is split in time: the earlier
     * share fits the map, and the later share - which the fit never saw -
     * decides whether it is an improvement. If it is not, no map is stored
     * and the row says why. Isotonic and Platt both get a turn, and the one
     * that actually did better on the unseen games wins; neither is assumed
     * to be the right shape in advance.
     *
     * @param  array<int, BacktestPrediction>  $predictions
     * @return array<string, mixed>
     */
    private function calibrate(array $predictions): array
    {
        // Ties train nothing - there is no third outcome to calibrate
        // toward - which is the same reason the regression skips them.
        $usable = array_values(array_filter($predictions, fn (BacktestPrediction $p) => ! $p->wasTie));

        if (count($usable) < self::MIN_CALIBRATION_SAMPLE) {
            return [
                'method' => 'none',
                'reason' => sprintf(
                    'Only %d out-of-sample predictions; a calibration map needs %d before it is describing the model rather than the noise.',
                    count($usable),
                    self::MIN_CALIBRATION_SAMPLE,
                ),
            ];
        }

        usort($usable, fn (BacktestPrediction $a, BacktestPrediction $b) => $a->startsAt <=> $b->startsAt);

        $split = (int) floor(count($usable) * self::CALIBRATION_FIT_SHARE);
        $fitSlice = array_slice($usable, 0, $split);
        $testSlice = array_slice($usable, $split);

        [$fitProbs, $fitOutcomes] = $this->columns($fitSlice);
        [$testProbs, $testOutcomes] = $this->columns($testSlice);

        $rawError = Metrics::expectedCalibrationError($testProbs, $testOutcomes);

        $isotonic = (new IsotonicRegression)->fit($fitProbs, $fitOutcomes);
        $platt = (new PlattScaling)->fit($fitProbs, $fitOutcomes);

        $candidates = [
            'isotonic' => [
                'error' => $this->errorAfter($isotonic->calibrate(...), $testProbs, $testOutcomes),
                'payload' => ['map' => $isotonic->map()],
            ],
            'platt' => [
                'error' => $this->errorAfter($platt->calibrate(...), $testProbs, $testOutcomes),
                'payload' => ['params' => $platt->params()],
            ],
        ];

        $best = null;

        foreach ($candidates as $method => $candidate) {
            if ($best === null || $candidate['error'] < $candidates[$best]['error']) {
                $best = $method;
            }
        }

        $measured = [
            'fitted_on' => count($fitSlice),
            'validated_on' => count($testSlice),
            'calibration_error' => [
                'raw' => $rawError,
                'isotonic' => $candidates['isotonic']['error'],
                'platt' => $candidates['platt']['error'],
            ],
        ];

        if ($candidates[$best]['error'] >= $rawError) {
            return [
                'method' => 'none',
                'reason' => 'Neither isotonic nor Platt improved calibration on the games the fit never saw, '
                    .'so the raw probability is left alone rather than adjusted toward whatever the earlier games happened to do.',
            ] + $measured;
        }

        return ['method' => $best] + $candidates[$best]['payload'] + $measured;
    }

    /**
     * Calibration error after passing every probability through a map.
     *
     * @param  callable(float): float  $through
     * @param  array<int, float>  $probabilities
     * @param  array<int, int>  $outcomes
     */
    private function errorAfter(callable $through, array $probabilities, array $outcomes): float
    {
        return Metrics::expectedCalibrationError(
            array_map(static fn (float $p) => $through($p), $probabilities),
            $outcomes,
        );
    }

    /**
     * @param  array<int, BacktestPrediction>  $predictions
     * @return array{0: array<int, float>, 1: array<int, int>}
     */
    private function columns(array $predictions): array
    {
        return [
            array_map(static fn (BacktestPrediction $p) => $p->probability, $predictions),
            array_map(static fn (BacktestPrediction $p) => $p->outcome, $predictions),
        ];
    }

    private function nextVersion(Sport $sport, Market $market, ModelType $model): int
    {
        return 1 + (int) ModelVersion::query()
            ->where('sport', $sport->value)
            ->where('market', $market->value)
            ->where('model_type', $model->value)
            ->max('version');
    }

    /**
     * The newest game the model could possibly have learned from. Anything
     * at or before this is history; anything after it is what the model is
     * entitled to predict.
     */
    private function trainedThrough(Sport $sport): CarbonImmutable
    {
        $newest = Event::query()
            ->forSport($sport)
            ->where('status', EventStatus::Final)
            ->max('starts_at');

        return $newest !== null ? CarbonImmutable::parse($newest) : CarbonImmutable::now();
    }
}
