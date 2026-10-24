<?php

declare(strict_types=1);

use App\Modeling\Odds\Arbitrage;

it('knows an ordinary market is not an arbitrage', function () {
    // -110 both ways is the standard hold: 104.76%, and no way through it.
    expect(Arbitrage::impliedSum(['home' => -110, 'away' => -110]))->toEqualWithDelta(1.047619, 0.000001)
        ->and(Arbitrage::exists(['home' => -110, 'away' => -110]))->toBeFalse()
        ->and(Arbitrage::profitPerUnit(['home' => -110, 'away' => -110]))->toBeLessThan(0.0);
});

it('finds the arbitrage when two books disagree far enough', function () {
    // +120 one side, -105 the other: 0.4545 + 0.5122 = 0.9667.
    $prices = ['home' => 120, 'away' => -105];

    expect(Arbitrage::exists($prices))->toBeTrue()
        ->and(Arbitrage::impliedSum($prices))->toEqualWithDelta(0.9667406, 0.000001)
        ->and(Arbitrage::profitPerUnit($prices))->toEqualWithDelta(0.034400, 0.00001);
});

it('splits the stake so every result returns the same', function () {
    $prices = ['home' => 120, 'away' => -105];
    $split = Arbitrage::stakeSplitCents($prices, 100000);

    expect(array_sum($split))->toBe(100000);

    $homeReturn = $split['home'] * 2.2;
    $awayReturn = $split['away'] * (1 + 100 / 105);

    // Equal to within the cent the split had to round to.
    expect(abs($homeReturn - $awayReturn))->toBeLessThan(300.0);
});

it('reports the profit that survives being rounded into whole cents', function () {
    $prices = ['home' => 120, 'away' => -105];
    $split = Arbitrage::stakeSplitCents($prices, 100000);

    // About 3.44% of $1,000, less whatever the rounding cost.
    expect(Arbitrage::guaranteedProfitCents($prices, $split))->toBeGreaterThan(3300)
        ->and(Arbitrage::guaranteedProfitCents($prices, $split))->toBeLessThanOrEqual(3440);
});

it('gives the leftover cent to whichever side is returning least', function () {
    // A total that cannot divide cleanly must still not leave one side short.
    $prices = ['home' => 120, 'away' => -105];
    $split = Arbitrage::stakeSplitCents($prices, 999);

    expect(array_sum($split))->toBe(999);
});

it('admits when an arbitrage is too thin to survive whole cents', function () {
    // A 0.1% edge on a $1 stake rounds away to nothing.
    $prices = ['home' => 101, 'away' => -100];
    $split = Arbitrage::stakeSplitCents($prices, 100);

    expect(Arbitrage::exists($prices))->toBeTrue()
        ->and(Arbitrage::guaranteedProfitCents($prices, $split))->toBeLessThanOrEqual(1);
});

it('refuses a one-sided market', function () {
    Arbitrage::impliedSum(['home' => -110]);
})->throws(InvalidArgumentException::class);

it('refuses a stake of nothing', function () {
    Arbitrage::stakeSplitCents(['home' => 120, 'away' => -105], 0);
})->throws(InvalidArgumentException::class);
