<?php

declare(strict_types=1);

use App\Modeling\Math\Metrics;

it('scores a perfect prediction at zero log loss', function () {
    expect(Metrics::logLoss([0.999999, 0.000001], [1, 0]))->toBeLessThan(0.0001);
});

it('matches log loss computed by hand', function () {
    // Two predictions of 0.5, one hit one miss: -log(0.5) each.
    expect(Metrics::logLoss([0.5, 0.5], [1, 0]))->toEqualWithDelta(log(2), 1e-12);
});

it('punishes confident wrongness far harder than cautious wrongness', function () {
    $confident = Metrics::logLoss([0.99], [0]);
    $cautious = Metrics::logLoss([0.55], [0]);

    // This is the whole reason log loss is the headline number.
    expect($confident)->toBeGreaterThan($cautious * 5);
});

it('scores Brier against the always fifty percent baseline', function () {
    expect(Metrics::brier([0.5, 0.5, 0.5, 0.5], [1, 0, 1, 0]))->toEqualWithDelta(0.25, 1e-12)
        ->and(Metrics::brier([1.0, 0.0], [1, 0]))->toEqualWithDelta(0.0, 1e-12);
});

it('reports positive skill only when it beats the baseline', function () {
    $outcomes = [1, 1, 0, 0, 1, 0];
    $market = [0.55, 0.55, 0.45, 0.45, 0.55, 0.45];
    $better = [0.70, 0.70, 0.30, 0.30, 0.70, 0.30];
    $worse = [0.50, 0.50, 0.50, 0.50, 0.50, 0.50];

    expect(Metrics::brierSkillScore($better, $market, $outcomes))->toBeGreaterThan(0.0)
        ->and(Metrics::brierSkillScore($worse, $market, $outcomes))->toBeLessThan(0.0)
        ->and(Metrics::brierSkillScore($market, $market, $outcomes))->toEqualWithDelta(0.0, 1e-12);
});

it('builds a calibration curve that matches what happened', function () {
    // Ten predictions at 0.9 where nine hit: perfectly calibrated.
    $probabilities = array_fill(0, 10, 0.9);
    $outcomes = [1, 1, 1, 1, 1, 1, 1, 1, 1, 0];

    $curve = Metrics::calibrationCurve($probabilities, $outcomes);

    expect($curve)->toHaveCount(1)
        ->and($curve[0]['n'])->toBe(10)
        ->and($curve[0]['mean_predicted'])->toEqualWithDelta(0.9, 1e-12)
        ->and($curve[0]['observed_rate'])->toEqualWithDelta(0.9, 1e-12);
});

it('measures calibration error as the gap between claim and reality', function () {
    // Says 90 percent, happens half the time: a 0.4 gap.
    $probabilities = array_fill(0, 10, 0.9);
    $outcomes = [1, 1, 1, 1, 1, 0, 0, 0, 0, 0];

    expect(Metrics::expectedCalibrationError($probabilities, $outcomes))->toEqualWithDelta(0.4, 1e-12);
});

it('gives a well ordered model an auc of one', function () {
    expect(Metrics::auc([0.9, 0.8, 0.2, 0.1], [1, 1, 0, 0]))->toEqualWithDelta(1.0, 1e-12);
});

it('gives a coin flip an auc of a half', function () {
    expect(Metrics::auc([0.5, 0.5, 0.5, 0.5], [1, 0, 1, 0]))->toEqualWithDelta(0.5, 1e-12);
});

it('separates ordering from calibration', function () {
    // Every winner ranked above every loser, so the ordering is perfect and
    // AUC is 1.0. But it claims roughly 95 percent on all four and only half
    // of them land, so it is wildly overconfident. This is exactly the model
    // that looks brilliant on a leaderboard and loses money at a sportsbook,
    // and it is why AUC never decides anything here.
    $probabilities = [0.99, 0.96, 0.94, 0.92];
    $outcomes = [1, 1, 0, 0];

    expect(Metrics::auc($probabilities, $outcomes))->toEqualWithDelta(1.0, 1e-12)
        ->and(Metrics::expectedCalibrationError($probabilities, $outcomes, 10))->toBeGreaterThan(0.4)
        // Log loss catches what AUC misses.
        ->and(Metrics::logLoss($probabilities, $outcomes))->toBeGreaterThan(1.0);
});

it('computes flat stake roi', function () {
    // Two bets at 2.0 decimal, one wins one loses: break even.
    expect(Metrics::flatRoi([1, 0], [2.0, 2.0]))->toEqualWithDelta(0.0, 1e-12)
        // Both win at 1.91: 0.91 profit per unit.
        ->and(Metrics::flatRoi([1, 1], [1.91, 1.91]))->toEqualWithDelta(0.91, 1e-12);
});

it('refuses to score nothing', function () {
    expect(fn () => Metrics::logLoss([], []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Metrics::logLoss([0.5], [1, 0]))->toThrow(InvalidArgumentException::class);
});
