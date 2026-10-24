<?php

declare(strict_types=1);

namespace App\Services\Modeling;

use App\Data\LoadedModel;
use App\Enums\Market;
use App\Enums\ModelType;
use App\Enums\Sport;
use App\Modeling\Math\StoredCalibration;
use App\Modeling\Predictors\LogisticPredictor;
use App\Modeling\Ratings\EloLeague;
use App\Modeling\Ratings\EloRater;
use App\Models\ModelVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Brings the active model version back to life.
 *
 * Two steps, and the second is the interesting one.
 *
 * Rebuilding is pure deserialization: the stored params carry the ratings,
 * the regression weights and the rolling form window, so the object that
 * comes back prices a game exactly as the trained one did.
 *
 * Then it is caught up. A model trained on Monday has not seen the weekend
 * that followed, and Elo ratings are state rather than parameters - an Elo
 * that has not been told last week's results is just out of date. So every
 * finished game after the version's trained_through_at is replayed into it,
 * in kickoff order, before anything is predicted.
 *
 * That replay cannot move the weights. A restored predictor carries no
 * training samples, so its refit threshold can never be reached, which is
 * exactly why LogisticPredictor::fromParams drops them. The weights named in
 * a prediction stay the weights that made it; only the ratings and the form
 * window move, and the count of games that moved them is recorded on every
 * prediction so the number can be reproduced.
 */
class ModelLoader
{
    public function __construct(private readonly TrainingSetBuilder $builder) {}

    /**
     * The active model for this sport and market, rebuilt and current, or
     * null if nothing has been trained and activated yet.
     */
    public function load(Sport $sport, Market $market = Market::Moneyline): ?LoadedModel
    {
        $version = $this->activeVersion($sport, $market);

        if ($version === null) {
            return null;
        }

        $params = (array) $version->params;

        if ($version->model_type === ModelType::Logistic) {
            $predictor = LogisticPredictor::fromParams($params);
            $replayed = $this->catchUp($sport, $version->trained_through_at, $predictor);

            return new LoadedModel(
                version: $version,
                league: $predictor->league(),
                predictor: $predictor,
                calibration: StoredCalibration::fromParams($version->calibration),
                caughtUpWith: $replayed,
            );
        }

        $league = isset($params['league'])
            ? EloLeague::fromParams((array) $params['league'])
            : $this->emptyLeague($sport);

        $replayed = $this->catchUp($sport, $version->trained_through_at, $league);

        return new LoadedModel(
            version: $version,
            league: $league,
            predictor: null,
            calibration: StoredCalibration::fromParams($version->calibration),
            caughtUpWith: $replayed,
        );
    }

    public function activeVersion(Sport $sport, Market $market = Market::Moneyline): ?ModelVersion
    {
        return ModelVersion::query()
            ->active()
            ->where('sport', $sport->value)
            ->where('market', $market->value)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Replay every finished game since the model was trained.
     *
     * Strictly after trained_through_at, because that timestamp is the
     * kickoff of the newest game the model already learned from, and
     * replaying it a second time would count one result twice.
     *
     * @param  EloLeague|LogisticPredictor  $model  anything that learns from a finished game
     */
    private function catchUp(Sport $sport, ?CarbonInterface $trainedThrough, object $model): int
    {
        if ($trainedThrough === null) {
            return 0;
        }

        $since = CarbonImmutable::parse($trainedThrough);
        $replayed = 0;

        foreach ($this->builder->games($sport, from: $since) as $game) {
            if (! $game->startsAt->greaterThan($since)) {
                continue;
            }

            $model->learn($game);
            $replayed++;
        }

        return $replayed;
    }

    private function emptyLeague(Sport $sport): EloLeague
    {
        return new EloLeague(new EloRater(
            homeAdvantage: (float) ($sport->config('home_advantage_points') ?? 2.0)
                * (float) ($sport->config('elo_per_point') ?? 25.0),
        ));
    }
}
