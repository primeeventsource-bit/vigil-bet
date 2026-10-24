<?php

declare(strict_types=1);

use App\Enums\VigMethod;
use App\Modeling\Odds\VigRemover;

it('measures the overround on a standard market', function () {
    // -110 each way implies 104.76 percent.
    expect(VigRemover::overround(['home' => -110, 'away' => -110]))
        ->toEqualWithDelta(0.047619, 1e-6);
});

it('converts overround into the hold the book actually keeps', function () {
    // 4.76 percent overround is a 4.55 percent hold on money wagered.
    expect(VigRemover::holdPercentage(['home' => -110, 'away' => -110]))
        ->toEqualWithDelta(0.045455, 1e-6);
});

it('makes a balanced market a coin flip', function () {
    $fair = VigRemover::fairProbabilities(['home' => -110, 'away' => -110]);

    expect($fair['home'])->toEqualWithDelta(0.5, 1e-9)
        ->and($fair['away'])->toEqualWithDelta(0.5, 1e-9);
});

it('always returns a distribution', function () {
    foreach ([VigMethod::Multiplicative, VigMethod::Power] as $method) {
        $fair = VigRemover::fairProbabilities(['home' => -250, 'away' => 200], $method);

        expect(array_sum($fair))->toEqualWithDelta(1.0, 1e-9);
    }
});

it('leaves the favourite favoured', function () {
    $fair = VigRemover::fairProbabilities(['home' => -250, 'away' => 200]);

    expect($fair['home'])->toBeGreaterThan($fair['away'])
        ->and($fair['home'])->toBeLessThan(0.7143); // below the vigged implied
});

it('strictly lowers every probability it touches', function () {
    // Removing margin can only ever reduce a vigged probability.
    $implied = ['home' => 1 / 1.4, 'away' => 1 / 3.0];
    $fair = VigRemover::fairProbabilities(['home' => -250, 'away' => 200]);

    expect($fair['home'])->toBeLessThan($implied['home'])
        ->and($fair['away'])->toBeLessThan($implied['away']);
});

it('disagrees with itself across methods on a lopsided market', function () {
    // The favourite-longshot bias is real: books take proportionally more
    // margin out of longshots, which is what the power method models. On a
    // heavy favourite the two methods differ by enough to flip a bet.
    $multiplicative = VigRemover::fairProbabilities(['home' => -2000, 'away' => 1000], VigMethod::Multiplicative);
    $power = VigRemover::fairProbabilities(['home' => -2000, 'away' => 1000], VigMethod::Power);

    expect(abs($multiplicative['away'] - $power['away']))->toBeGreaterThan(0.005)
        ->and(array_sum($power))->toEqualWithDelta(1.0, 1e-9);
});

it('agrees with itself when the market is symmetric', function () {
    $multiplicative = VigRemover::fairProbabilities(['home' => -110, 'away' => -110], VigMethod::Multiplicative);
    $power = VigRemover::fairProbabilities(['home' => -110, 'away' => -110], VigMethod::Power);

    expect($power['home'])->toEqualWithDelta($multiplicative['home'], 1e-6);
});

it('handles a three way market', function () {
    // Soccer, where the draw is a real outcome rather than a push.
    $fair = VigRemover::fairProbabilities(['home' => 150, 'draw' => 230, 'away' => 190]);

    expect(array_sum($fair))->toEqualWithDelta(1.0, 1e-9)
        ->and($fair)->toHaveKeys(['home', 'draw', 'away'])
        ->and($fair['home'])->toBeGreaterThan($fair['draw']);
});

it('survives an arbitrage market where the book is underround', function () {
    // Two books disagreeing can imply under 100 percent. The method must
    // still return a distribution rather than diverging.
    $fair = VigRemover::fairProbabilities(['home' => 120, 'away' => 120], VigMethod::Power);

    expect(array_sum($fair))->toEqualWithDelta(1.0, 1e-9)
        ->and($fair['home'])->toEqualWithDelta(0.5, 1e-6);
});

it('refuses a one sided market', function () {
    expect(fn () => VigRemover::fairProbabilities(['home' => -110]))
        ->toThrow(InvalidArgumentException::class);
});
