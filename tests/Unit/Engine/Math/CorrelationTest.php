<?php

declare(strict_types=1);

use App\Modeling\Math\Correlation;

it('finds a perfect positive relationship', function () {
    $x = [1.0, 2.0, 3.0, 4.0, 5.0];
    $y = [2.0, 4.0, 6.0, 8.0, 10.0];

    expect(Correlation::pearson($x, $y))->toEqualWithDelta(1.0, 1e-9)
        ->and(Correlation::slope($x, $y))->toEqualWithDelta(2.0, 1e-9);
});

it('finds a perfect negative relationship', function () {
    $x = [1.0, 2.0, 3.0, 4.0, 5.0];
    $y = [10.0, 8.0, 6.0, 4.0, 2.0];

    expect(Correlation::pearson($x, $y))->toEqualWithDelta(-1.0, 1e-9)
        ->and(Correlation::slope($x, $y))->toEqualWithDelta(-2.0, 1e-9);
});

it('reports the slope in the units of the thing being measured', function () {
    // Every 10 of x takes 1.4 off y, which is a statement you can check
    // against a price. A correlation coefficient is not.
    $x = [0.0, 10.0, 20.0, 30.0];
    $y = [45.0, 43.6, 42.2, 40.8];

    expect(Correlation::slope($x, $y))->toEqualWithDelta(-0.14, 1e-9);
});

it('says nothing when one series never moves', function () {
    // No variance means nothing to correlate, not a divide by zero.
    expect(Correlation::pearson([5.0, 5.0, 5.0, 5.0], [1.0, 2.0, 3.0, 4.0]))->toBe(0.0)
        ->and(Correlation::slope([5.0, 5.0, 5.0, 5.0], [1.0, 2.0, 3.0, 4.0]))->toBe(0.0);
});

it('carries the sample size and a t statistic, never a bare coefficient', function () {
    // The point of the summary: a correlation on its own is large by
    // accident on a small sample and trivial on a large one.
    $summary = Correlation::summarise(
        [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0],
        [2.1, 3.9, 6.2, 7.8, 10.1, 12.2, 13.8, 16.1],
    );

    expect($summary['n'])->toBe(8)
        ->and($summary['r'])->toBeGreaterThan(0.99)
        ->and($summary['t'])->toBeGreaterThan(20.0)
        ->and($summary['slope'])->toBeGreaterThan(1.9);
});

it('gives a small t statistic to noise', function () {
    // Deliberately unrelated series. A real relationship has to clear this.
    mt_srand(11);

    $x = [];
    $y = [];

    foreach (range(1, 60) as $ignored) {
        $x[] = (float) mt_rand(0, 100);
        $y[] = (float) mt_rand(0, 100);
    }

    expect(abs(Correlation::summarise($x, $y)['t']))->toBeLessThan(2.0);
});

it('refuses series of different lengths', function () {
    Correlation::pearson([1.0, 2.0, 3.0], [1.0, 2.0]);
})->throws(InvalidArgumentException::class);

it('refuses a sample too small to mean anything', function () {
    Correlation::pearson([1.0, 2.0], [1.0, 2.0]);
})->throws(InvalidArgumentException::class);
