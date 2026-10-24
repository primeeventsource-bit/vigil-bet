<?php

declare(strict_types=1);

use App\Enums\Verdict;
use App\Modeling\Backtest\Verdicter;

beforeEach(fn () => $this->verdicter = new Verdicter(minPredictions: 300));

it('refuses a verdict on too small a sample', function () {
    $result = $this->verdicter->decide(
        predictions: 120,
        modelLogLoss: 0.60,
        marketLogLoss: 0.68,
        modelCalibrationError: 0.02,
        marketCalibrationError: 0.03,
    );

    // Even results this good prove nothing over 120 games.
    expect($result->verdict)->toBe(Verdict::Insufficient)
        ->and($result->allowsRecommendation())->toBeFalse()
        ->and($result->reason)->toContain('120 of 300');
});

it('refuses a verdict when there is no closing line to measure against', function () {
    // The normal state for a sport with no free line history.
    $result = $this->verdicter->decide(
        predictions: 500,
        modelLogLoss: 0.60,
        marketLogLoss: null,
    );

    expect($result->verdict)->toBe(Verdict::Insufficient)
        ->and($result->reason)->toContain('no benchmark');
});

it('proves a model that beats the market on log loss and is calibrated with it', function () {
    $result = $this->verdicter->decide(
        predictions: 600,
        modelLogLoss: 0.6650,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.018,
        marketCalibrationError: 0.020,
        improvementTStat: 2.4,
    );

    expect($result->verdict)->toBe(Verdict::Proven)
        ->and($result->allowsRecommendation())->toBeTrue()
        ->and($result->logLossImprovement)->toEqualWithDelta(0.015, 1e-9)
        ->and($result->calibrationGap)->toEqualWithDelta(-0.002, 1e-9);
});

it('refuses to prove a sharper model whose probabilities are miscalibrated', function () {
    // Better at ranking games, worse at stating how likely they are. Kelly
    // reads the number literally, so this is the dangerous case, not the
    // merely disappointing one.
    $result = $this->verdicter->decide(
        predictions: 600,
        modelLogLoss: 0.6650,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.060,
        marketCalibrationError: 0.020,
        improvementTStat: 2.4,
    );

    expect($result->verdict)->toBe(Verdict::Unproven)
        ->and($result->allowsRecommendation())->toBeFalse()
        ->and($result->reason)->toContain('worse calibrated');
});

it('refuses to prove a well calibrated model that does not beat the market', function () {
    $result = $this->verdicter->decide(
        predictions: 600,
        modelLogLoss: 0.6850,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.010,
        marketCalibrationError: 0.020,
        improvementTStat: -0.8,
    );

    expect($result->verdict)->toBe(Verdict::Unproven)
        ->and($result->reason)->toContain('Neither measure separates');
});

it('will not call unmeasurable calibration a pass', function () {
    // A model that beats the market on log loss but whose calibration could
    // not be compared has cleared one condition, not two.
    $result = $this->verdicter->decide(
        predictions: 600,
        modelLogLoss: 0.6650,
        marketLogLoss: 0.6800,
        modelCalibrationError: null,
        marketCalibrationError: null,
        improvementTStat: 2.4,
    );

    expect($result->verdict)->toBe(Verdict::Unproven)
        ->and($result->reason)->toContain('could not be measured');
});

it('calls a model that loses to the market by more than chance negative', function () {
    $result = $this->verdicter->decide(
        predictions: 600,
        modelLogLoss: 0.7000,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.030,
        marketCalibrationError: 0.020,
        improvementTStat: -3.1,
    );

    expect($result->verdict)->toBe(Verdict::Negative)
        ->and($result->allowsRecommendation())->toBeFalse()
        ->and($result->reason)->toContain('Do not bet this market');
});

it('needs the improvement to clear the noise, not merely to exist', function () {
    // Ahead of the market, but by less than this sample could have produced
    // by accident.
    $result = $this->verdicter->decide(
        predictions: 600,
        modelLogLoss: 0.6799,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.015,
        marketCalibrationError: 0.020,
        improvementTStat: 0.4,
    );

    expect($result->verdict)->toBe(Verdict::Unproven)
        ->and($result->reason)->toContain('standard errors from zero');
});

it('treats a model that merely matches the market as unproven', function () {
    $result = $this->verdicter->decide(
        predictions: 600,
        modelLogLoss: 0.6800,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.020,
        marketCalibrationError: 0.020,
        improvementTStat: 0.0,
    );

    expect($result->verdict)->toBe(Verdict::Unproven)
        ->and($result->reason)->toContain('Neither measure separates');
});

/*
 * The gate's second condition used to be "positive average CLV", read off
 * the backtest. It was not CLV, and it was free: shading every home
 * probability down a couple of points moved it from negative to positive
 * without changing anything the model knew. This locks the door.
 */
it('does not let a home-side lean influence the verdict at all', function () {
    $verdicts = [];

    foreach ([-0.05, -0.02, 0.0, 0.02, 0.05] as $bias) {
        $verdicts[] = $this->verdicter->decide(
            predictions: 600,
            modelLogLoss: 0.7000,
            marketLogLoss: 0.6800,
            modelCalibrationError: 0.030,
            marketCalibrationError: 0.020,
            improvementTStat: -3.1,
            meanHomeBias: $bias,
        )->verdict;
    }

    expect(array_unique(array_map(fn ($v) => $v->value, $verdicts)))
        ->toBe([Verdict::Negative->value]);
});

it('reports the home-side lean without testing it', function () {
    $result = $this->verdicter->decide(
        predictions: 600,
        modelLogLoss: 0.6650,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.018,
        marketCalibrationError: 0.020,
        improvementTStat: 2.4,
        meanHomeBias: -0.031,
    );

    expect($result->verdict)->toBe(Verdict::Proven)
        ->and($result->meanHomeBias)->toEqualWithDelta(-0.031, 1e-9)
        ->and($result->toArray())->toHaveKey('mean_home_bias');
});

it('does not let profit buy a verdict', function () {
    // ROI is not an input at all. Over a few hundred bets it is variance.
    $result = $this->verdicter->decide(
        predictions: 600,
        modelLogLoss: 0.6900,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.040,
        marketCalibrationError: 0.020,
        improvementTStat: -2.2,
    );

    expect($result->verdict)->toBe(Verdict::Negative);
});

it('carries a reason with every verdict', function () {
    $cases = [
        [120, 0.6000, 0.68, 0.02, 0.03, 1.0],
        [600, 0.6650, 0.68, 0.018, 0.020, 2.4],
        [600, 0.7000, 0.68, 0.030, 0.020, -3.1],
        [600, 0.6800, 0.68, 0.020, 0.020, 0.0],
    ];

    foreach ($cases as $case) {
        $result = $this->verdicter->decide(...$case);

        expect($result->reason)->not->toBeEmpty()
            ->and($result->toArray())->toHaveKeys(['verdict', 'reason']);
    }
});

it('reads its thresholds from config', function () {
    config()->set('edge.verdict.min_predictions', 50);
    config()->set('edge.verdict.calibration_tolerance', 0.01);

    $result = Verdicter::fromConfig()->decide(
        predictions: 60,
        modelLogLoss: 0.6650,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.018,
        marketCalibrationError: 0.020,
        improvementTStat: 2.4,
    );

    expect($result->verdict)->toBe(Verdict::Proven);
});

it('lets a tight calibration tolerance disqualify a model a loose one allows', function () {
    $args = [
        'predictions' => 600,
        'modelLogLoss' => 0.6650,
        'marketLogLoss' => 0.6800,
        'modelCalibrationError' => 0.025,
        'marketCalibrationError' => 0.020,
        'improvementTStat' => 2.4,
    ];

    expect((new Verdicter(minPredictions: 300, calibrationTolerance: 0.01))->decide(...$args)->verdict)
        ->toBe(Verdict::Proven)
        ->and((new Verdicter(minPredictions: 300, calibrationTolerance: 0.001))->decide(...$args)->verdict)
        ->toBe(Verdict::Unproven);
});
