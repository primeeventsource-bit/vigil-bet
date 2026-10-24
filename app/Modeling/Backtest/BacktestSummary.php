<?php

declare(strict_types=1);

namespace App\Modeling\Backtest;

/**
 * Everything a backtest produced, in one object the verdict gate can read.
 */
final readonly class BacktestSummary
{
    /** @param  array<int, array<string, mixed>>  $calibrationCurve */
    public function __construct(
        public int $predictions,
        public int $withMarket,
        public float $logLoss,
        public float $brier,
        public float $calibrationError,
        public float $auc,
        public ?float $modelLogLossVsMarket,
        public ?float $marketLogLoss,
        public ?float $marketBrier,
        public ?float $marketCalibrationError,
        public ?float $modelCalibrationErrorVsMarket,
        public ?float $brierSkill,
        /**
         * Mean gap between the market's fair home probability and the
         * model's, in probability points. Positive means the model is
         * shorter on home teams than the price is.
         *
         * Reported, never a gate. See BacktestPrediction::marketGap() for
         * why: a constant shade moves this number without changing anything
         * the model knows.
         */
        public ?float $meanHomeBias,
        public ?float $flatRoi,
        public ?float $logLossDiffMean = null,
        public ?float $logLossDiffStdError = null,
        public array $calibrationCurve = [],
    ) {}

    public static function empty(): self
    {
        return new self(
            predictions: 0,
            withMarket: 0,
            logLoss: 0.0,
            brier: 0.0,
            calibrationError: 0.0,
            auc: 0.5,
            modelLogLossVsMarket: null,
            marketLogLoss: null,
            marketBrier: null,
            marketCalibrationError: null,
            modelCalibrationErrorVsMarket: null,
            brierSkill: null,
            meanHomeBias: null,
            flatRoi: null,
        );
    }

    /** How much better than the market, in log loss. Positive is better. */
    public function logLossImprovement(): ?float
    {
        if ($this->marketLogLoss === null || $this->modelLogLossVsMarket === null) {
            return null;
        }

        return $this->marketLogLoss - $this->modelLogLossVsMarket;
    }

    /**
     * How much better calibrated than the market, in probability points.
     * Positive is better, because calibration error is a distance from
     * perfect and smaller is better.
     */
    public function calibrationImprovement(): ?float
    {
        if ($this->marketCalibrationError === null || $this->modelCalibrationErrorVsMarket === null) {
            return null;
        }

        return $this->marketCalibrationError - $this->modelCalibrationErrorVsMarket;
    }

    /**
     * How many standard errors the improvement is above zero.
     *
     * This is the number that separates a real edge from a lucky sample. An
     * improvement of 0.001 means nothing on its own; an improvement of 0.001
     * that is three standard errors from zero means something.
     */
    public function improvementTStat(): ?float
    {
        if ($this->logLossDiffMean === null || $this->logLossDiffStdError === null) {
            return null;
        }

        if ($this->logLossDiffStdError <= 0.0) {
            return null;
        }

        return $this->logLossDiffMean / $this->logLossDiffStdError;
    }

    public function verdict(?Verdicter $verdicter = null): VerdictResult
    {
        $verdicter ??= Verdicter::fromConfig();

        return $verdicter->decide(
            predictions: $this->withMarket > 0 ? $this->withMarket : $this->predictions,
            modelLogLoss: $this->modelLogLossVsMarket ?? $this->logLoss,
            marketLogLoss: $this->marketLogLoss,
            modelCalibrationError: $this->modelCalibrationErrorVsMarket,
            marketCalibrationError: $this->marketCalibrationError,
            improvementTStat: $this->improvementTStat(),
            meanHomeBias: $this->meanHomeBias,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'predictions' => $this->predictions,
            'with_market' => $this->withMarket,
            'log_loss' => $this->logLoss,
            'brier' => $this->brier,
            'calibration_error' => $this->calibrationError,
            'auc' => $this->auc,
            'model_log_loss_vs_market' => $this->modelLogLossVsMarket,
            'market_log_loss' => $this->marketLogLoss,
            'market_brier' => $this->marketBrier,
            'market_calibration_error' => $this->marketCalibrationError,
            'model_calibration_error_vs_market' => $this->modelCalibrationErrorVsMarket,
            'brier_skill' => $this->brierSkill,
            'mean_home_bias' => $this->meanHomeBias,
            'flat_roi' => $this->flatRoi,
            'log_loss_improvement' => $this->logLossImprovement(),
            'calibration_improvement' => $this->calibrationImprovement(),
            'log_loss_diff_mean' => $this->logLossDiffMean,
            'log_loss_diff_std_error' => $this->logLossDiffStdError,
            'improvement_t_stat' => $this->improvementTStat(),
        ];
    }
}
