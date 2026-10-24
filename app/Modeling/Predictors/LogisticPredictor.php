<?php

declare(strict_types=1);

namespace App\Modeling\Predictors;

use App\Data\BacktestGame;
use App\Data\PreGameContext;
use App\Modeling\Features\FeatureBuilder;
use App\Modeling\Features\FeatureSchema;
use App\Modeling\Math\LogisticRegression;
use App\Modeling\Ratings\EloLeague;

/**
 * Elo, plus a learned correction.
 *
 * The rating system supplies the base number and a logistic fit adjusts it
 * for the things Elo structurally cannot see: rest, the short week, the
 * roof, divisional familiarity, and current form as distinct from season
 * long strength.
 *
 * Refitting is incremental in time, not in data. Every refit throws the old
 * weights away and fits from scratch on every game played so far, which is
 * slower but has the property that matters: the training set at any moment
 * is exactly the past. There is no window to get wrong and no state carried
 * over from a fit that saw a different slice of history.
 *
 * Until there are enough games to fit on, probability() returns null and the
 * walk-forward runner scores nothing. An untrained model abstaining is the
 * honest answer; filling the gap with Elo would make the backtest a blend
 * of two models reported as one.
 */
final class LogisticPredictor
{
    private ?LogisticRegression $model = null;

    /** @var array<int, array<int, float>> */
    private array $samples = [];

    /** @var array<int, int> */
    private array $labels = [];

    private int $sinceRefit = 0;

    private int $trainedOn = 0;

    private int $refits = 0;

    /** @var array{iterations:int, loss:float, converged:bool}|null */
    private ?array $lastFit = null;

    public function __construct(
        private readonly EloLeague $league,
        private readonly FeatureBuilder $features,
        /** Games needed before the fit is attempted at all. */
        private readonly int $minimumTraining = 300,
        /** How many new games may arrive before the fit is redone. */
        private readonly int $refitEvery = 100,
        private readonly float $l2 = 1.0,
        private readonly float $learningRate = 0.3,
        private readonly int $maxIterations = 600,
    ) {}

    /** Home win probability, or null while the model is still untrained. */
    public function probability(PreGameContext $context): ?float
    {
        if ($this->model === null) {
            return null;
        }

        return $this->model->predict($this->vector($context));
    }

    /**
     * Learn from a finished game.
     *
     * The order inside here is the whole guarantee. The feature vector is
     * built first, while the accumulators still hold only earlier games, and
     * only then do Elo and the form window get told what happened. Swapping
     * those two blocks would put each game's own result into its own
     * features, and the backtest would look superb.
     */
    public function learn(BacktestGame $game): void
    {
        // Ties carry no information about which side was better and there
        // is no third label to give them, so they train nothing. They are
        // still shown to Elo and to form, which both handle a draw.
        if (! $game->wasTie()) {
            $this->samples[] = $this->vector($game->context());
            $this->labels[] = $game->homeWon();
            $this->sinceRefit++;
            $this->trainedOn++;
        }

        $this->league->learn($game);
        $this->features->learn($game);

        $this->refitIfDue();
    }

    /** @return array<int, float> */
    public function vector(PreGameContext $context): array
    {
        return $this->features->vector($context, $this->league->probability($context));
    }

    /**
     * What the model currently believes, by feature name. This is what makes
     * a number arguable rather than merely produced.
     *
     * @return array<string, float>
     */
    public function coefficients(): array
    {
        if ($this->model === null) {
            return [];
        }

        return FeatureSchema::label($this->model->weights());
    }

    /**
     * Why this particular game is priced where it is, in log odds per
     * feature.
     *
     * @return array<string, float>
     */
    public function explain(PreGameContext $context): array
    {
        if ($this->model === null) {
            return [];
        }

        return FeatureSchema::label($this->model->contributions($this->vector($context)));
    }

    public function isTrained(): bool
    {
        return $this->model !== null;
    }

    public function trainingSize(): int
    {
        return $this->trainedOn;
    }

    public function refits(): int
    {
        return $this->refits;
    }

    /** @return array{iterations:int, loss:float, converged:bool}|null */
    public function lastFit(): ?array
    {
        return $this->lastFit;
    }

    public function league(): EloLeague
    {
        return $this->league;
    }

    /** Everything needed to rebuild this predictor, for storage. */
    public function toParams(): array
    {
        return [
            'feature_version' => FeatureSchema::VERSION,
            'features' => FeatureSchema::names(),
            'league' => $this->league->toParams(),
            'features_state' => $this->features->toParams(),
            'regression' => $this->model?->params(),
            'training_games' => $this->trainedOn,
            'refits' => $this->refits,
            'hyperparameters' => [
                'l2' => $this->l2,
                'learning_rate' => $this->learningRate,
                'max_iterations' => $this->maxIterations,
                'minimum_training' => $this->minimumTraining,
                'refit_every' => $this->refitEvery,
            ],
        ];
    }

    /**
     * Rebuild a predictor from stored parameters.
     *
     * The training samples are deliberately not carried. They are only
     * needed to refit, and a restored model never refits: it is a frozen
     * version that predictions are attributed to, and quietly continuing to
     * learn would mean the thing named in a prediction row is not the thing
     * that made it. Retraining writes a new version instead.
     *
     * @param  array<string, mixed>  $params
     */
    public static function fromParams(array $params): self
    {
        $predictor = new self(
            EloLeague::fromParams((array) ($params['league'] ?? [])),
            FeatureBuilder::fromParams((array) ($params['features_state'] ?? [])),
            minimumTraining: (int) ($params['hyperparameters']['minimum_training'] ?? 300),
            refitEvery: (int) ($params['hyperparameters']['refit_every'] ?? 100),
            l2: (float) ($params['hyperparameters']['l2'] ?? 1.0),
            learningRate: (float) ($params['hyperparameters']['learning_rate'] ?? 0.3),
            maxIterations: (int) ($params['hyperparameters']['max_iterations'] ?? 600),
        );

        if (($params['regression'] ?? null) !== null) {
            $predictor->model = LogisticRegression::fromParams((array) $params['regression']);
        }

        $predictor->trainedOn = (int) ($params['training_games'] ?? 0);
        $predictor->refits = (int) ($params['refits'] ?? 0);

        return $predictor;
    }

    private function refitIfDue(): void
    {
        if (count($this->samples) < $this->minimumTraining) {
            return;
        }

        // The first fit happens the moment there is enough history; after
        // that, only once another batch has accumulated.
        if ($this->model !== null && $this->sinceRefit < $this->refitEvery) {
            return;
        }

        $model = new LogisticRegression(
            learningRate: $this->learningRate,
            maxIterations: $this->maxIterations,
            l2: $this->l2,
        );

        $this->lastFit = $model->fit($this->samples, $this->labels);
        $this->model = $model;
        $this->sinceRefit = 0;
        $this->refits++;
    }
}
