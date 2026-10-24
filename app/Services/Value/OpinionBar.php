<?php

declare(strict_types=1);

namespace App\Services\Value;

use App\Models\ModelVersion;

/**
 * Whether a market's model is honest enough to have an opinion published.
 *
 * Not the verdict gate, and deliberately not. The gate asks whether a
 * model beats the closing line, which is the question for selling a pick
 * and the wrong one for publishing an opinion: no model here beats it,
 * and waiting for one means never publishing anything, while the
 * evidence that would settle it only accumulates once something is
 * published. The gate is circular by construction.
 *
 * The bar here asks the question the product's own claim depends on.
 * An opinion is shown with a confidence figure - "74%" - so the thing
 * that has to be true is that 74% is roughly 74%. That is calibration,
 * and it is measured against outcomes rather than against a price, so it
 * can be known without ever beating a book.
 *
 * It is a weaker claim than the gate's and it is a real one. A market
 * that clears this is a market where the number shown means what it
 * says; it is not a market where the number is better than the
 * market's. Nothing in the product may imply otherwise, which is why
 * every published opinion carries its own accuracy with its sample size.
 *
 * The thresholds come from the models as they actually are rather than
 * from taste. At 0.06, the five moneyline markets clear and every
 * spreads model is refused - their confidence figures are wrong by nine
 * to twenty-three points, and publishing one would be publishing a
 * number known to be false.
 */
class OpinionBar
{
    /**
     * Out-of-sample predictions a model needs before its calibration
     * means anything.
     *
     * Matches the verdict gate's own minimum. Below this, a calibration
     * error is mostly an artefact of the sample it was measured on.
     */
    public const MINIMUM_PREDICTIONS = 300;

    /**
     * How far a stated probability may sit from the observed rate.
     *
     * Six points. Wide enough to admit a model that is useful and
     * imperfect, narrow enough that "74%" is not quietly 60%. Every
     * spreads model in the engine fails it, which is the point: those
     * numbers are wrong by more than this and always were.
     */
    public const MAXIMUM_CALIBRATION_ERROR = 0.06;

    /**
     * Whether this model's probabilities may be published as an opinion.
     */
    public function allows(?ModelVersion $version): bool
    {
        return $this->refusal($version) === null;
    }

    /**
     * Why this model may not be published, or null if it may.
     *
     * A reason rather than a boolean because it is shown to an operator
     * and written into the opinion's own row: "we are not publishing
     * NCAAB" should be answerable without reading this class.
     */
    public function refusal(?ModelVersion $version): ?string
    {
        if ($version === null) {
            return 'There is no active model for this market.';
        }

        $metrics = $version->holdout_metrics ?? [];
        $predictions = (int) ($metrics['predictions'] ?? 0);
        $calibration = $metrics['calibration_error'] ?? null;

        if ($predictions < self::MINIMUM_PREDICTIONS) {
            return sprintf(
                'Only %s predictions measured out of sample, and %s are needed before a confidence figure means anything.',
                number_format($predictions),
                number_format(self::MINIMUM_PREDICTIONS),
            );
        }

        if ($calibration === null) {
            return 'This model has never had its calibration measured.';
        }

        if ((float) $calibration > self::MAXIMUM_CALIBRATION_ERROR) {
            return sprintf(
                'Its stated probabilities are off by %s points on average, and %s is the most we will publish.',
                number_format((float) $calibration * 100, 1),
                number_format(self::MAXIMUM_CALIBRATION_ERROR * 100, 1),
            );
        }

        return null;
    }

    /**
     * How well calibrated this model is, as a percentage, or null.
     *
     * For showing beside an opinion. Not a win rate and not an edge: it
     * says how close the stated probabilities have landed to what
     * actually happened.
     */
    public function calibrationPercent(?ModelVersion $version): ?float
    {
        $error = $version?->holdout_metrics['calibration_error'] ?? null;

        return $error === null ? null : round((float) $error * 100, 1);
    }
}
