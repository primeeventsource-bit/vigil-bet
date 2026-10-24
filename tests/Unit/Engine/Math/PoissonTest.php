<?php

declare(strict_types=1);

use App\Modeling\Math\Poisson;

it('matches hand computable pmf values', function () {
    // P(0; 1) = e^-1
    expect(Poisson::pmf(0, 1.0))->toEqualWithDelta(exp(-1), 1e-12)
        // P(1; 1) = e^-1
        ->and(Poisson::pmf(1, 1.0))->toEqualWithDelta(exp(-1), 1e-12)
        // P(2; 3) = 9/2 * e^-3
        ->and(Poisson::pmf(2, 3.0))->toEqualWithDelta(4.5 * exp(-3), 1e-12);
});

it('sums to one over its support', function () {
    $total = 0.0;

    for ($k = 0; $k <= 60; $k++) {
        $total += Poisson::pmf($k, 3.1);
    }

    expect($total)->toEqualWithDelta(1.0, 1e-12);
});

it('stays finite for counts that would overflow a naive formula', function () {
    // lambda^k and k! both overflow long before the probability does.
    expect(Poisson::pmf(170, 170.0))->toBeGreaterThan(0.0)
        ->and(is_finite(Poisson::pmf(170, 170.0)))->toBeTrue();
});

it('prices a hockey moneyline from two scoring rates', function () {
    // A 3.1 to 2.8 edge is a real but modest favourite once ties are split
    // out, which is why hockey moneylines sit near even money so often.
    $outcome = Poisson::matchOutcome(3.1, 2.8);

    expect($outcome['home'])->toBeGreaterThan($outcome['away'])
        ->and($outcome['home'] + $outcome['draw'] + $outcome['away'])->toEqualWithDelta(1.0, 1e-9)
        ->and($outcome['home'])->toBeLessThan(0.5);
});

it('gives identical teams identical chances', function () {
    $outcome = Poisson::matchOutcome(3.0, 3.0);

    expect($outcome['home'])->toEqualWithDelta($outcome['away'], 1e-12);
});

it('prices a total over a half point line with no push', function () {
    $total = Poisson::totalProbabilities(3.1, 2.8, 5.5);

    expect($total['push'])->toBe(0.0)
        ->and($total['over'] + $total['under'])->toEqualWithDelta(1.0, 1e-9);
});

it('accounts for the push on a whole number total', function () {
    $total = Poisson::totalProbabilities(3.0, 3.0, 6.0);

    expect($total['push'])->toBeGreaterThan(0.1)
        ->and($total['over'] + $total['under'] + $total['push'])->toEqualWithDelta(1.0, 1e-9);
});

it('raises the over as scoring rates rise', function () {
    $low = Poisson::totalProbabilities(2.5, 2.5, 5.5)['over'];
    $high = Poisson::totalProbabilities(3.5, 3.5, 5.5)['over'];

    expect($high)->toBeGreaterThan($low);
});

it('rejects a non positive rate', function () {
    expect(fn () => Poisson::pmf(1, 0.0))->toThrow(InvalidArgumentException::class);
});
