<?php

declare(strict_types=1);

namespace App\Modeling\Backtest;

use App\Enums\VigMethod;
use App\Modeling\Math\Metrics;

/**
 * Turns a walk-forward run into the numbers that decide a verdict.
 *
 * The model is always scored against the same games as the market, never
 * against a different subset: a model that looks good only on the games it
 * had a price for has not been compared to anything.
 */
final class BacktestEvaluator
{
    public function __construct(private readonly VigMethod $method = VigMethod::Multiplicative) {}

    /**
     * @param  array<int, BacktestPrediction>  $predictions
     */
    public function evaluate(array $predictions): BacktestSummary
    {
        if ($predictions === []) {
            return BacktestSummary::empty();
        }

        $modelAll = [];
        $outcomesAll = [];

        // Only the subset with a closing market can be compared to it, and
        // both sides of that comparison use exactly these games.
        $modelVsMarket = [];
        $marketProbs = [];
        $outcomesVsMarket = [];
        $marketGaps = [];
        $pairedDiffs = [];
        $flatOutcomes = [];
        $flatOdds = [];

        foreach ($predictions as $prediction) {
            $modelAll[] = $prediction->probability;
            $outcomesAll[] = $prediction->outcome;

            $market = $prediction->marketProbability($this->method);

            if ($market === null) {
                continue;
            }

            $modelVsMarket[] = $prediction->probability;
            $marketProbs[] = $market;
            $outcomesVsMarket[] = $prediction->outcome;
            $marketGaps[] = $prediction->marketGap($this->method);

            // Paired per-game difference. Comparing two aggregate log
            // losses cannot tell skill from noise; comparing them game by
            // game can, because the games are the same games.
            $pairedDiffs[] = self::loss($market, $prediction->outcome)
                - self::loss($prediction->probability, $prediction->outcome);

            // Flat stake ROI, betting the home side every time a price
            // existed. A blunt instrument, reported but never a gate.
            $decimal = $prediction->closingDecimal();

            if ($decimal !== null) {
                $flatOutcomes[] = $prediction->outcome;
                $flatOdds[] = $decimal;
            }
        }

        $hasMarket = $modelVsMarket !== [];

        return new BacktestSummary(
            predictions: count($predictions),
            withMarket: count($modelVsMarket),
            logLoss: Metrics::logLoss($modelAll, $outcomesAll),
            brier: Metrics::brier($modelAll, $outcomesAll),
            calibrationError: Metrics::expectedCalibrationError($modelAll, $outcomesAll),
            auc: Metrics::auc($modelAll, $outcomesAll),
            modelLogLossVsMarket: $hasMarket ? Metrics::logLoss($modelVsMarket, $outcomesVsMarket) : null,
            marketLogLoss: $hasMarket ? Metrics::logLoss($marketProbs, $outcomesVsMarket) : null,
            marketBrier: $hasMarket ? Metrics::brier($marketProbs, $outcomesVsMarket) : null,
            // The market's own calibration on exactly these games, so the
            // gate can ask whether the model is better calibrated than the
            // price rather than better than an invented threshold.
            marketCalibrationError: $hasMarket
                ? Metrics::expectedCalibrationError($marketProbs, $outcomesVsMarket)
                : null,
            modelCalibrationErrorVsMarket: $hasMarket
                ? Metrics::expectedCalibrationError($modelVsMarket, $outcomesVsMarket)
                : null,
            brierSkill: $hasMarket ? Metrics::brierSkillScore($modelVsMarket, $marketProbs, $outcomesVsMarket) : null,
            meanHomeBias: $hasMarket ? self::mean(array_filter($marketGaps, fn ($v) => $v !== null)) : null,
            logLossDiffMean: $hasMarket ? self::mean($pairedDiffs) : null,
            logLossDiffStdError: $hasMarket ? self::standardError($pairedDiffs) : null,
            flatRoi: $flatOdds !== [] ? Metrics::flatRoi($flatOutcomes, $flatOdds) : null,
            calibrationCurve: Metrics::calibrationCurve($modelAll, $outcomesAll),
        );
    }

    /** Negative log likelihood of one prediction. */
    private static function loss(float $probability, int $outcome): float
    {
        $p = min(1 - 1e-15, max(1e-15, $probability));

        return $outcome === 1 ? -log($p) : -log(1 - $p);
    }

    /** @param  array<int, float>  $values */
    private static function mean(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /**
     * Standard error of the mean. This is what turns "better" into
     * "better by more than this sample could produce by chance".
     *
     * @param  array<int, float>  $values
     */
    private static function standardError(array $values): ?float
    {
        $n = count($values);

        if ($n < 2) {
            return null;
        }

        $mean = self::mean($values);
        $sumSquares = 0.0;

        foreach ($values as $value) {
            $sumSquares += ($value - $mean) ** 2;
        }

        return sqrt($sumSquares / ($n - 1)) / sqrt($n);
    }
}
