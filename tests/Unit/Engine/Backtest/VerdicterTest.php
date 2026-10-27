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
    /*
     * Better at ranking games, worse at stating how likely they are.
     * Whoever reads the number reads it literally, so this is the
     * dangerous case rather than the merely disappointing one - and it
     * clears neither tier: not proven, because the probabilities are
     * wrong, and not publishable as calibrated for the same reason.
     *
     * 11 points of error, against the 6 the calibrated tier allows.
     */
    $result = $this->verdicter->decide(
        predictions: 600,
        modelLogLoss: 0.6650,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.110,
        marketCalibrationError: 0.020,
        improvementTStat: 2.4,
    );

    expect($result->verdict)->toBe(Verdict::Unproven)
        ->and($result->allowsRecommendation())->toBeFalse()
        ->and($result->reason)->toContain('worse calibrated');
});

it('calls a model calibrated when it does not beat the market, and says it does not', function () {
    /*
     * Beating the no-vig close is the strongest claim a sports model can
     * make and nothing here makes it - every one of the eleven live
     * markets loses to the close.
     *
     * What this model can say is narrower and true: when it says 60%, it
     * happens about 60% of the time. That earns the calibrated tier and
     * the reason carries the rest of the truth with it - but it does not
     * earn a recommendation, because a recommendation is read as an edge
     * claim and this tier has no edge to claim.
     */
    $result = $this->verdicter->decide(
        predictions: 600,
        modelLogLoss: 0.6850,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.010,
        marketCalibrationError: 0.020,
        improvementTStat: -0.8,
    );

    expect($result->verdict)->toBe(Verdict::Calibrated)
        // Measured, and still not a recommendation.
        ->and($result->allowsRecommendation())->toBeFalse()
        ->and($result->verdict->claimsEdge())->toBeFalse()
        ->and($result->reason)->toContain('loses to the closing line');
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

it('still condemns a model that is both worse than the market and miscalibrated', function () {
    /*
     * The warning that has to survive the new tier. A model losing to the
     * close whose own numbers are also wrong has nothing honest to say,
     * and the board should discourage the market rather than publish it.
     *
     * 12 points of calibration error, against the 6 the tier allows.
     */
    $result = $this->verdicter->decide(
        predictions: 600,
        modelLogLoss: 0.7000,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.120,
        marketCalibrationError: 0.020,
        improvementTStat: -3.1,
    );

    expect($result->verdict)->toBe(Verdict::Negative)
        ->and($result->allowsRecommendation())->toBeFalse()
        ->and($result->reason)->toContain('Do not bet this market');
});

it('needs the improvement to clear the noise before it will call anything proven', function () {
    /*
     * Ahead of the market, but by less than this sample could have thrown
     * up by accident. Not proven - that claim needs the margin to survive
     * its own standard error.
     *
     * Its probabilities are sound, so it is publishable as a calibrated
     * market. The two statements are independent and both are made.
     */
    $result = $this->verdicter->decide(
        predictions: 600,
        modelLogLoss: 0.6799,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.015,
        marketCalibrationError: 0.020,
        improvementTStat: 0.4,
    );

    expect($result->verdict)->not->toBe(Verdict::Proven)
        ->and($result->verdict)->toBe(Verdict::Calibrated)
        ->and($result->verdict->claimsEdge())->toBeFalse();
});

it('treats a model that matches the market but misstates its own odds as unproven', function () {
    /*
     * Neither claim is available: it does not beat the close, and its
     * stated probabilities are off by 9 points against the 6 the
     * calibrated tier allows. Unproven is the honest answer.
     */
    $result = $this->verdicter->decide(
        predictions: 600,
        modelLogLoss: 0.6800,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.090,
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

    // The point is that every bias produces the same answer, whatever
    // that answer is - a lean toward the home side is reported and never
    // tested.
    expect(array_unique(array_map(fn ($v) => $v->value, $verdicts)))
        ->toHaveCount(1);
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
    /*
     * ROI is not an input at all. Over a few hundred bets it is variance,
     * and a profitable run cannot promote a model that is worse than the
     * market on log loss and wrong about its own probabilities.
     */
    $result = $this->verdicter->decide(
        predictions: 600,
        modelLogLoss: 0.6900,
        marketLogLoss: 0.6800,
        modelCalibrationError: 0.140,
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
        /*
         * Tightened past what this model can show, it loses the proven
         * claim. It keeps the weaker one, because its own probabilities
         * are still sound - which is the distinction the calibrated tier
         * exists to make.
         */
        ->and((new Verdicter(minPredictions: 300, calibrationTolerance: 0.001))->decide(...$args)->verdict)
        ->toBe(Verdict::Calibrated);
});
