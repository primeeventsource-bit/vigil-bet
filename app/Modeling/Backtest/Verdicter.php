<?php

declare(strict_types=1);

namespace App\Modeling\Backtest;

use App\Enums\Verdict;

/**
 * Decides whether a sport and market has earned the right to produce picks.
 *
 * This is the gate the whole app rests on. Everything upstream can be
 * beautiful and it still does not matter: if the model cannot beat the
 * closing line, the honest answer is that it has no edge, and the board
 * should say so rather than quietly showing bets anyway.
 *
 * Two tests have to pass together, and both compare the model to the price
 * on exactly the same games:
 *
 *  - beat the no-vig market on log loss, by more than this sample could
 *    plausibly have produced by chance;
 *  - be no worse calibrated than the market, because stake sizing reads the
 *    probability literally. A model can rank games better than the price and
 *    still state 70 percent where the truth is 60, and Kelly will happily
 *    bet four times too much on it.
 *
 * ROI is deliberately not a gate. Over a few hundred bets it is mostly
 * variance.
 *
 * Closing line value is not a gate either, and that is a correction rather
 * than a choice. The second condition here used to be "positive average
 * CLV", computed from the backtest as the mean gap between the market's fair
 * probability and the model's. That quantity is not CLV. CLV needs a price a
 * bet was struck at and a different, later price it closed at; a backtest
 * over closing lines has only one price, so the subtraction measures which
 * way the model leans on home teams and nothing else. It was also free to
 * pass: shading every home probability down three points moved it from
 * -1.8 percent to +1.2 percent with the model's AUC unchanged to three
 * decimal places, which was enough to lift a verdict off NEGATIVE.
 *
 * Real closing line value is measured on placed bets, at the price actually
 * taken, against the close that followed. It belongs to the bet ledger, it
 * accumulates only as bets are made, and it cannot be recovered from
 * historical closing lines at all.
 */
final class Verdicter
{
    public function __construct(
        private readonly int $minPredictions = 300,
        private readonly float $minLogLossImprovement = 0.0,
        /**
         * How many standard errors above zero the log loss improvement has
         * to sit. Without this, any epsilon counts as beating the market,
         * and sampling noise supplies an epsilon about half the time.
         */
        private readonly float $minTStat = 1.64,
        /**
         * How much worse calibrated than the market the model may be, in
         * probability points, before it is disqualified. Zero would demand
         * the model win on both measures outright; a small allowance stops a
         * genuinely sharper model failing on calibration noise.
         */
        private readonly float $calibrationTolerance = 0.01,
        /*
         * How far this model's own stated probabilities may sit from
         * the observed rate before it has nothing honest to say. The
         * same 6 points OpinionBar already publishes against, so the
         * board and the opinions cannot disagree about which markets
         * are fit to show.
         */
        private readonly float $ownCalibrationTolerance = 0.06,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            minPredictions: (int) config('edge.verdict.min_predictions', 300),
            minLogLossImprovement: (float) config('edge.verdict.min_log_loss_improvement', 0.0),
            minTStat: (float) config('edge.verdict.min_t_stat', 1.64),
            calibrationTolerance: (float) config('edge.verdict.calibration_tolerance', 0.01),
            ownCalibrationTolerance: (float) config('edge.verdict.own_calibration_tolerance', 0.06),
        );
    }

    /**
     * @param  float|null  $marketLogLoss  null when no closing line exists to
     *                                     compare against, which is the normal
     *                                     state for a sport with no line history
     * @param  float|null  $meanHomeBias  reported in the reason, never tested
     */
    public function decide(
        int $predictions,
        float $modelLogLoss,
        ?float $marketLogLoss,
        ?float $modelCalibrationError = null,
        ?float $marketCalibrationError = null,
        ?float $improvementTStat = null,
        ?float $meanHomeBias = null,
    ): VerdictResult {
        if ($predictions < $this->minPredictions) {
            return new VerdictResult(
                Verdict::Insufficient,
                sprintf(
                    '%d of %d graded predictions needed before a verdict is possible.',
                    $predictions,
                    $this->minPredictions,
                ),
            );
        }

        if ($marketLogLoss === null) {
            return new VerdictResult(
                Verdict::Insufficient,
                'No closing lines to measure against, so there is no benchmark and no verdict.',
            );
        }

        // Negative means the model scored lower loss, which is better.
        $improvement = $marketLogLoss - $modelLogLoss;

        // Two conditions, not one. The margin has to be positive, and it has
        // to be larger than this sample could plausibly have produced by
        // chance. A model that merely is the market clears the first and
        // fails the second, which is the correct answer.
        $significant = $improvementTStat === null || $improvementTStat >= $this->minTStat;
        $beatsMarket = $improvement > $this->minLogLossImprovement && $significant;

        $calibrationGap = $this->calibrationGap($modelCalibrationError, $marketCalibrationError);
        // Unknown calibration is not a pass. If the comparison could not be
        // made, the model has not cleared it.
        $wellCalibrated = $calibrationGap !== null && $calibrationGap <= $this->calibrationTolerance;

        if ($beatsMarket && $wellCalibrated) {
            return new VerdictResult(
                Verdict::Proven,
                sprintf(
                    'Beats the closing line on log loss by %.4f over %d predictions, %.1f standard errors from zero, and is calibrated %s the market.',
                    $improvement,
                    $predictions,
                    $improvementTStat ?? 0.0,
                    $calibrationGap <= 0 ? 'at least as well as' : 'close to',
                ),
                $improvement,
                $meanHomeBias,
                $calibrationGap,
            );
        }

        /*
         * A market whose own probabilities hold up, even though it does
         * not beat the close.
         *
         * Decided before the negative branch on purpose. Losing to the
         * closing line is the ordinary condition of an honest model - the
         * close is the sharpest number in the sport - and treating that
         * as "do not bet this market" left all eleven markets condemned
         * and VIGIL publishing nothing it could ever publish.
         *
         * What this tier asserts is narrower and checkable: when the
         * model says 60%, it happens about 60% of the time. It is not an
         * edge, it is not a claim about the book, and every surface that
         * shows it says so.
         */
        $ownCalibration = $modelCalibrationError === null
            ? null
            : abs($modelCalibrationError);

        if ($ownCalibration !== null && $ownCalibration <= $this->ownCalibrationTolerance) {
            return new VerdictResult(
                Verdict::Calibrated,
                sprintf(
                    'Stated probabilities land within %.1f points of the observed rate over %d predictions. '
                    .'Honest numbers, not an edge: it still loses to the closing line by %.4f on log loss.',
                    $ownCalibration * 100,
                    $predictions,
                    abs($improvement),
                ),
                $improvement,
                $meanHomeBias,
                $calibrationGap,
            );
        }

        // Worse on log loss by more than chance explains, and not
        // calibrated either, is evidence the model is wrong - the board
        // should discourage the market rather than leave it looking like
        // a work in progress.
        $meaningfullyWorse = $improvement < 0
            && ($improvementTStat === null || $improvementTStat <= -$this->minTStat);

        if ($meaningfullyWorse) {
            return new VerdictResult(
                Verdict::Negative,
                sprintf(
                    'Loses to the closing line by %.4f on log loss, %.1f standard errors from zero. Do not bet this market.',
                    abs($improvement),
                    $improvementTStat ?? 0.0,
                ),
                $improvement,
                $meanHomeBias,
                $calibrationGap,
            );
        }

        return new VerdictResult(
            Verdict::Unproven,
            $this->explainUnproven($beatsMarket, $improvement, $calibrationGap, $improvementTStat),
            $improvement,
            $meanHomeBias,
            $calibrationGap,
        );
    }

    /**
     * How much worse calibrated the model is than the market. Negative means
     * the model is better calibrated.
     */
    private function calibrationGap(?float $model, ?float $market): ?float
    {
        if ($model === null || $market === null) {
            return null;
        }

        return $model - $market;
    }

    private function explainUnproven(
        bool $beatsMarket,
        float $improvement,
        ?float $calibrationGap,
        ?float $tStat = null,
    ): string {
        if (! $beatsMarket && $improvement > 0 && $tStat !== null && $tStat < $this->minTStat) {
            return sprintf(
                'Ahead of the market by %.4f on log loss, but only %.2f standard errors from zero. That is within what chance produces on this sample.',
                $improvement,
                $tStat,
            );
        }

        if ($calibrationGap === null) {
            return sprintf(
                'Log loss is %+.4f against the market, but the market\'s own calibration could not be measured, so there is nothing to compare against.',
                $improvement,
            );
        }

        if ($beatsMarket) {
            return sprintf(
                'Beats the market on log loss by %.4f, but its probabilities are %.1f points worse calibrated than the price. Stake sizing reads those probabilities literally, so that is not safe to bet.',
                $improvement,
                $calibrationGap * 100,
            );
        }

        return sprintf(
            'Neither measure separates this model from the market: log loss %+.4f, calibration %+.1f points against it.',
            $improvement,
            $calibrationGap * 100,
        );
    }
}
