<?php

declare(strict_types=1);

use App\Modeling\Math\IsotonicRegression;
use App\Modeling\Math\Metrics;
use App\Modeling\Math\PlattScaling;
use App\Modeling\Math\StoredCalibration;

/**
 * An overconfident model: it ranks correctly but states numbers that are too
 * far from the middle. This is the common failure, and the one that makes
 * every Kelly stake too big.
 *
 * @return array{0: array<int, float>, 1: array<int, int>}
 */
function overconfidentSample(int $n = 600): array
{
    mt_srand(7);
    $predictions = [];
    $outcomes = [];

    for ($i = 0; $i < $n; $i++) {
        $stated = 0.05 + ($i % 90) / 100;
        // What actually happens is much closer to a coin flip.
        $true = 0.5 + ($stated - 0.5) * 0.55;

        $predictions[] = $stated;
        $outcomes[] = (mt_rand(0, 9999) / 10000) < $true ? 1 : 0;
    }

    return [$predictions, $outcomes];
}

it('leaves an already monotone relationship ordered', function () {
    $isotonic = (new IsotonicRegression)->fit([0.2, 0.4, 0.6, 0.8], [0, 0, 1, 1]);

    expect($isotonic->calibrate(0.8))->toBeGreaterThanOrEqual($isotonic->calibrate(0.2));
});

it('never lets a higher prediction calibrate lower', function () {
    [$predictions, $outcomes] = overconfidentSample();
    $isotonic = (new IsotonicRegression)->fit($predictions, $outcomes);

    $previous = 0.0;

    foreach (range(1, 99) as $step) {
        $calibrated = $isotonic->calibrate($step / 100);

        expect($calibrated)->toBeGreaterThanOrEqual($previous - 1e-12);

        $previous = $calibrated;
    }
});

it('pools a violating pair into their shared average', function () {
    // Predictions 0.3 and 0.7, but the lower one wins and the higher loses.
    // The only monotone fit is to give both the same value.
    $isotonic = (new IsotonicRegression)->fit([0.3, 0.7], [1, 0]);

    expect($isotonic->calibrate(0.3))->toEqualWithDelta(0.5, 1e-9)
        ->and($isotonic->calibrate(0.7))->toEqualWithDelta(0.5, 1e-9);
});

it('refuses to claim certainty from a finite sample', function () {
    // Eight for eight is not a hundred percent, and treating it as one makes
    // log loss infinite the first time it is wrong.
    $isotonic = (new IsotonicRegression)->fit(
        [0.9, 0.91, 0.92, 0.93, 0.94, 0.95, 0.96, 0.97],
        [1, 1, 1, 1, 1, 1, 1, 1],
    );

    expect($isotonic->calibrate(0.95))->toBeLessThan(1.0)
        ->and($isotonic->calibrate(0.95))->toBeGreaterThan(0.9);
});

it('improves calibration error on an overconfident model', function () {
    [$predictions, $outcomes] = overconfidentSample();

    $before = Metrics::expectedCalibrationError($predictions, $outcomes);

    $isotonic = (new IsotonicRegression)->fit($predictions, $outcomes);
    $calibrated = array_map(fn (float $p) => $isotonic->calibrate($p), $predictions);

    $after = Metrics::expectedCalibrationError($calibrated, $outcomes);

    expect($after)->toBeLessThan($before);
});

it('improves log loss rather than just moving numbers around', function () {
    [$predictions, $outcomes] = overconfidentSample();

    $isotonic = (new IsotonicRegression)->fit($predictions, $outcomes);
    $calibrated = array_map(fn (float $p) => $isotonic->calibrate($p), $predictions);

    expect(Metrics::logLoss($calibrated, $outcomes))->toBeLessThan(Metrics::logLoss($predictions, $outcomes));
});

it('round trips an isotonic map through storage', function () {
    [$predictions, $outcomes] = overconfidentSample();

    $isotonic = (new IsotonicRegression)->fit($predictions, $outcomes);
    $rebuilt = IsotonicRegression::fromMap($isotonic->map());

    foreach ([0.2, 0.45, 0.6, 0.85] as $p) {
        expect($rebuilt->calibrate($p))->toEqualWithDelta($isotonic->calibrate($p), 1e-12);
    }
});

it('pulls an overconfident model toward the middle with Platt', function () {
    [$predictions, $outcomes] = overconfidentSample();

    $platt = (new PlattScaling(learningRate: 0.3, maxIterations: 4000))->fit($predictions, $outcomes);

    // a below 1 is the signature of damping overconfidence.
    expect($platt->params()['a'])->toBeLessThan(1.0)
        ->and($platt->calibrate(0.9))->toBeLessThan(0.9)
        ->and($platt->calibrate(0.1))->toBeGreaterThan(0.1);
});

it('improves log loss with Platt too', function () {
    [$predictions, $outcomes] = overconfidentSample();

    $platt = (new PlattScaling(learningRate: 0.3, maxIterations: 4000))->fit($predictions, $outcomes);
    $calibrated = array_map(fn (float $p) => $platt->calibrate($p), $predictions);

    expect(Metrics::logLoss($calibrated, $outcomes))->toBeLessThan(Metrics::logLoss($predictions, $outcomes));
});

it('keeps Platt monotone', function () {
    [$predictions, $outcomes] = overconfidentSample();
    $platt = (new PlattScaling(learningRate: 0.3, maxIterations: 2000))->fit($predictions, $outcomes);

    expect($platt->calibrate(0.8))->toBeGreaterThan($platt->calibrate(0.4));
});

it('round trips Platt parameters', function () {
    [$predictions, $outcomes] = overconfidentSample();

    $platt = (new PlattScaling)->fit($predictions, $outcomes);
    $rebuilt = PlattScaling::fromParams($platt->params());

    expect($rebuilt->calibrate(0.7))->toEqualWithDelta($platt->calibrate(0.7), 1e-12);
});

it('refuses to calibrate before being fitted', function () {
    expect(fn () => (new IsotonicRegression)->calibrate(0.5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new PlattScaling)->calibrate(0.5))->toThrow(InvalidArgumentException::class);
});

/*
 * Applying whatever a model version stored at training time.
 *
 * The passthrough case carries its weight: ModelTrainer stores "none" when
 * neither map improved calibration on games the fit never saw, and a caller
 * should not have to remember that an uncalibrated model exists.
 */

it('passes a probability straight through when no map was stored', function () {
    $calibration = StoredCalibration::fromParams(['method' => 'none', 'reason' => 'did not help']);

    expect($calibration->isApplied())->toBeFalse()
        ->and($calibration->apply(0.62))->toBe(0.62);
});

it('treats a missing calibration as no calibration', function () {
    expect(StoredCalibration::fromParams(null)->apply(0.62))->toBe(0.62)
        ->and(StoredCalibration::fromParams([])->apply(0.62))->toBe(0.62);
});

it('replays a stored isotonic map', function () {
    [$predictions, $outcomes] = overconfidentSample();

    $fitted = (new IsotonicRegression)->fit($predictions, $outcomes);

    $stored = StoredCalibration::fromParams(['method' => 'isotonic', 'map' => $fitted->map()]);

    expect($stored->isApplied())->toBeTrue()
        ->and($stored->apply(0.9))->toEqualWithDelta($fitted->calibrate(0.9), 1e-12);
});

it('replays a stored platt fit', function () {
    [$predictions, $outcomes] = overconfidentSample();

    $fitted = (new PlattScaling)->fit($predictions, $outcomes);

    $stored = StoredCalibration::fromParams(['method' => 'platt', 'params' => $fitted->params()]);

    expect($stored->isApplied())->toBeTrue()
        ->and($stored->apply(0.9))->toEqualWithDelta($fitted->calibrate(0.9), 1e-12);
});
