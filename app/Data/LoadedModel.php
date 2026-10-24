<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ModelType;
use App\Modeling\Math\StoredCalibration;
use App\Modeling\Predictors\LogisticPredictor;
use App\Modeling\Ratings\EloLeague;
use App\Models\ModelVersion;

/**
 * A stored model version, rebuilt and ready to price a game.
 *
 * The version is carried alongside the maths because every prediction has to
 * name the model that produced it. Nothing here can be asked for a
 * probability without also being able to say which row it came from.
 *
 * `caughtUpWith` counts the finished games replayed into this model since it
 * was trained. Elo ratings are state rather than parameters - an Elo that
 * has not seen last weekend is simply out of date - so the loader brings
 * them forward before predicting. The weights never move during that replay:
 * a restored predictor carries no training samples and so cannot refit,
 * which is what keeps the version named in a prediction honest.
 */
final readonly class LoadedModel
{
    public function __construct(
        public ModelVersion $version,
        public EloLeague $league,
        public ?LogisticPredictor $predictor,
        public StoredCalibration $calibration,
        public int $caughtUpWith = 0,
    ) {}

    /**
     * The model's home win probability, calibrated, or null if the model
     * declines to price this game.
     */
    public function probability(PreGameContext $context): ?float
    {
        $raw = $this->rawProbability($context);

        return $raw === null ? null : $this->calibration->apply($raw);
    }

    /** Before the calibration map, for the record of how the number was made. */
    public function rawProbability(PreGameContext $context): ?float
    {
        return $this->version->model_type === ModelType::Logistic
            ? $this->predictor?->probability($context)
            : $this->league->probability($context);
    }

    /**
     * What drove this particular number, in log odds per feature. Empty for
     * a plain rating model, which has no features to attribute anything to.
     *
     * @return array<string, float>
     */
    public function contributions(PreGameContext $context): array
    {
        return $this->version->model_type === ModelType::Logistic
            ? ($this->predictor?->explain($context) ?? [])
            : [];
    }

    /** @return array<string, float> */
    public function ratings(int $homeTeamId, int $awayTeamId): array
    {
        return [
            'home_rating' => $this->league->ratingFor($homeTeamId),
            'away_rating' => $this->league->ratingFor($awayTeamId),
        ];
    }
}
