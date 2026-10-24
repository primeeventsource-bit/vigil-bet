<?php

declare(strict_types=1);

use App\Modeling\Math\Normal;
use App\Modeling\Math\Poisson;

it('matches known values of the error function', function () {
    expect(Normal::erf(0.0))->toEqualWithDelta(0.0, 1e-9)
        ->and(Normal::erf(1.0))->toEqualWithDelta(0.8427007929, 1e-6)
        ->and(Normal::erf(-1.0))->toEqualWithDelta(-0.8427007929, 1e-6)
        ->and(Normal::erf(2.0))->toEqualWithDelta(0.9953222650, 1e-6);
});

it('matches the standard normal table', function () {
    expect(Normal::cdf(0.0))->toEqualWithDelta(0.5, 1e-9)
        ->and(Normal::cdf(1.0))->toEqualWithDelta(0.8413447461, 1e-6)
        ->and(Normal::cdf(1.96))->toEqualWithDelta(0.9750021049, 1e-6)
        ->and(Normal::cdf(-1.645))->toEqualWithDelta(0.0499848, 1e-5);
});

it('is symmetric about the mean', function () {
    foreach ([0.3, 1.1, 2.7] as $z) {
        expect(Normal::cdf($z) + Normal::cdf(-$z))->toEqualWithDelta(1.0, 1e-9);
    }
});

it('inverts the cdf', function () {
    // Round trips to 1e-9 across the range, measured worst at p = 0.5. That
    // is the floor the erf approximation imposes, and it is seven orders of
    // magnitude finer than any sports model is accurate to.
    foreach ([0.01, 0.25, 0.5, 0.75, 0.99] as $p) {
        expect(Normal::cdf(Normal::inverseCdf($p)))->toEqualWithDelta($p, 1e-8);
    }
});

it('holds its stated precision across the whole range', function () {
    // Pinned so a future change to erf cannot quietly make every probability
    // in the app less accurate.
    $worst = 0.0;

    for ($i = 1; $i < 1000; $i++) {
        $p = $i / 1000;
        $worst = max($worst, abs(Normal::cdf(Normal::inverseCdf($p)) - $p));
    }

    expect($worst)->toBeLessThan(1e-8);
});

it('keeps erf within the Abramowitz and Stegun error bound', function () {
    // 7.1.26 is documented to 1.5e-7. Checked against the Taylor series.
    $series = function (float $x): float {
        $sum = 0.0;

        for ($n = 0; $n < 60; $n++) {
            $sum += ((-1) ** $n) * ($x ** (2 * $n + 1))
                / (exp(Poisson::logFactorial($n)) * (2 * $n + 1));
        }

        return 2 / sqrt(M_PI) * $sum;
    };

    $worst = 0.0;

    for ($x = -30; $x <= 30; $x++) {
        $worst = max($worst, abs(Normal::erf($x / 10) - $series($x / 10)));
    }

    expect($worst)->toBeLessThan(1.5e-7);
});

it('knows the familiar critical values', function () {
    expect(Normal::inverseCdf(0.975))->toEqualWithDelta(1.959964, 1e-5)
        ->and(Normal::inverseCdf(0.95))->toEqualWithDelta(1.644854, 1e-5);
});

it('scales to a mean and standard deviation', function () {
    // An NFL game projected to be won by 3 with a 13.5 point spread of
    // outcomes: the favourite wins a bit under 60 percent of the time.
    $winProbability = Normal::survival(0.0, mean: 3.0, sd: 13.5);

    expect($winProbability)->toBeGreaterThan(0.58)->toBeLessThan(0.59);
});

it('prices a spread cover as the chance of beating the number', function () {
    // Laying 3 when projected to win by 3 is close to a coin flip, slightly
    // under because the push at exactly 3 is excluded here.
    $cover = Normal::survival(3.0, mean: 3.0, sd: 13.5);

    expect($cover)->toEqualWithDelta(0.5, 1e-9);
});

it('rejects a non positive standard deviation', function () {
    expect(fn () => Normal::cdf(1.0, 0.0, 0.0))->toThrow(InvalidArgumentException::class);
});

it('rejects an impossible probability when inverting', function () {
    expect(fn () => Normal::inverseCdf(0.0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Normal::inverseCdf(1.0))->toThrow(InvalidArgumentException::class);
});
