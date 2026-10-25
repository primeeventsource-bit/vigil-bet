<?php

declare(strict_types=1);

use App\Modeling\Math\NegativeBinomial as NB;
use App\Modeling\Math\Poisson;

it('computes log gamma against known values', function () {
    // Gamma(n) = (n-1)! so logGamma(5) = log(24)
    expect(NB::logGamma(5.0))->toEqualWithDelta(log(24), 1e-10)
        ->and(NB::logGamma(1.0))->toEqualWithDelta(0.0, 1e-10)
        // Gamma(1/2) = sqrt(pi)
        ->and(NB::logGamma(0.5))->toEqualWithDelta(log(sqrt(M_PI)), 1e-10);
});

it('sums to one over its support', function () {
    $total = 0.0;

    for ($k = 0; $k <= 100; $k++) {
        $total += NB::pmf($k, 4.5, 6.0);
    }

    expect($total)->toEqualWithDelta(1.0, 1e-9);
});

it('approaches Poisson as dispersion grows', function () {
    // Large r means variance approaches the mean, which is Poisson.
    foreach ([0, 2, 5, 9] as $k) {
        expect(NB::pmf($k, 4.0, 1e7))->toEqualWithDelta(Poisson::pmf($k, 4.0), 1e-4);
    }
});

it('puts more weight in the tail than Poisson for the same mean', function () {
    // This is the whole reason baseball totals need it: a 12 run game is
    // rare, not nearly impossible, and Poisson says nearly impossible.
    $poisson = Poisson::survival(11, 9.0);
    $negBin = NB::survival(11, 9.0, 4.0);

    expect($negBin)->toBeGreaterThan($poisson);
});

it('recovers dispersion from a mean and variance', function () {
    // variance = mean + mean^2 / r, so r = mean^2 / (variance - mean)
    expect(NB::dispersionFrom(4.5, 9.0))->toEqualWithDelta(4.5, 1e-9);
});

it('declines to invent dispersion when a sample is not overdispersed', function () {
    // Variance at or below the mean means Poisson, not a forced parameter.
    expect(NB::dispersionFrom(4.5, 4.5))->toBeNull()
        ->and(NB::dispersionFrom(4.5, 3.0))->toBeNull();
});

it('prices a baseball moneyline', function () {
    $outcome = NB::matchOutcome(4.8, 4.3, 4.0);

    expect($outcome['home'])->toBeGreaterThan($outcome['away'])
        ->and($outcome['home'] + $outcome['draw'] + $outcome['away'])->toEqualWithDelta(1.0, 1e-9);
});

it('convolves two teams for a total rather than adding parameters', function () {
    $total = NB::totalProbabilities(4.8, 4.3, 4.0, 8.5);

    expect($total['push'])->toBe(0.0)
        ->and($total['over'] + $total['under'])->toEqualWithDelta(1.0, 1e-9)
        ->and($total['over'])->toBeGreaterThan(0.3)
        ->and($total['over'])->toBeLessThan(0.7);
});

it('accounts for the push on a whole number run total', function () {
    $total = NB::totalProbabilities(4.5, 4.5, 4.0, 9.0);

    expect($total['push'])->toBeGreaterThan(0.0)
        ->and($total['over'] + $total['under'] + $total['push'])->toEqualWithDelta(1.0, 1e-9);
});

it('rejects impossible parameters', function () {
    expect(fn () => NB::pmf(1, 0.0, 4.0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => NB::pmf(1, 4.0, 0.0))->toThrow(InvalidArgumentException::class);
});

it('prices one count against a line without convolving it with anything', function () {
    /*
     * A game total is the sum of two teams and has to be convolved. A
     * player prop is one count - his own rebounds - and running it
     * through the two-variable form would price the sum of two players
     * who do not exist.
     *
     * A rebounder averaging 8 with a variance of 12 is dispersion
     * (8*8)/(12-8) = 16.
     */
    $outcomes = NB::outcomes(8.0, 16.0, 7.5);

    expect($outcomes['push'])->toBe(0.0)
        ->and($outcomes['over'] + $outcomes['under'])->toEqualWithDelta(1.0, 1e-9)
        // Just above his average, so a little under even money.
        ->and($outcomes['over'])->toBeGreaterThan(0.45)
        ->and($outcomes['over'])->toBeLessThan(0.55);
});

it('leaves the push out of both sides on a whole-number prop line', function () {
    /*
     * Exactly eight rebounds against a line of eight is neither a win
     * nor a loss, and folding it into either side overstates that side.
     * On a low count the push is worth several points.
     */
    $outcomes = NB::outcomes(8.0, 16.0, 8.0);

    expect($outcomes['push'])->toBeGreaterThan(0.05)
        ->and($outcomes['over'] + $outcomes['under'] + $outcomes['push'])
        ->toEqualWithDelta(1.0, 1e-9);
});

it('gives a prop tail more weight than Poisson would', function () {
    /*
     * The reason this family is used for props at all. Poisson ties the
     * variance to the mean, so it understates exactly the tail an over
     * bet is bought from - and understating it turns a bet with value
     * into a pass.
     */
    expect(NB::outcomes(8.0, 16.0, 11.5)['over'])
        ->toBeGreaterThan(Poisson::survival(11, 8.0));
});

it('refuses a negative prop line rather than answering about one', function () {
    expect(fn () => NB::outcomes(8.0, 16.0, -1.0))->toThrow(InvalidArgumentException::class);
});
