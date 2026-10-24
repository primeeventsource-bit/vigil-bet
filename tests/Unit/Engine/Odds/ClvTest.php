<?php

declare(strict_types=1);

use App\Modeling\Odds\Clv;

it('scores a bet that beat the close as positive', function () {
    // Took -110, closed -130: the market moved toward the bet.
    expect(Clv::versusClosingPrice(-110, -130))->toBeGreaterThan(0.0)
        ->and(Clv::beatTheClose(-110, -130))->toBeTrue();
});

it('scores a bet the market moved away from as negative', function () {
    expect(Clv::versusClosingPrice(-130, -110))->toBeLessThan(0.0)
        ->and(Clv::beatTheClose(-130, -110))->toBeFalse();
});

it('states value in probability points', function () {
    // -110 is 52.38 percent, -130 is 56.52. Just over four points.
    expect(Clv::versusClosingPrice(-110, -130))->toEqualWithDelta(0.0414, 0.0005);
});

it('quotes value as a percentage of price too', function () {
    // 1.909 against 1.769 is about 7.9 percent better odds.
    expect(Clv::pricePercentage(-110, -130))->toEqualWithDelta(0.0791, 0.0005);
});

it('measures against the fair close rather than the vigged one', function () {
    // Closing market -130 / +110. The raw close flatters the bet because it
    // still contains the hold; stripping it gives the honest number.
    $raw = Clv::versusClosingPrice(-110, -130);
    $fair = Clv::versusFairClose(-110, ['home' => -130, 'away' => 110], 'home');

    expect($fair)->toBeLessThan($raw)
        ->and($fair)->toBeGreaterThan(0.0);
});

it('can show a bet that looks good against the raw close and is not', function () {
    // Took -115 on a side that closed -120 with +100 the other way. Against
    // the raw price that is positive; against the fair close it is not,
    // because the whole move was margin.
    $raw = Clv::versusClosingPrice(-115, -120);
    $fair = Clv::versusFairClose(-115, ['home' => -120, 'away' => 100], 'home');

    expect($raw)->toBeGreaterThan(0.0)
        ->and($fair)->toBeLessThan(0.0);
});

it('returns null when the closing market is incomplete', function () {
    expect(Clv::versusFairClose(-110, ['home' => -130], 'home'))->toBeNull()
        ->and(Clv::versusFairClose(-110, ['home' => -130, 'away' => 110], 'over'))->toBeNull();
});

it('averages across a run of bets, ignoring the ones that cannot be scored', function () {
    expect(Clv::average([0.02, 0.04, null, -0.01]))->toEqualWithDelta(0.016667, 1e-5)
        ->and(Clv::average([]))->toBe(0.0);
});

it('treats an unmoved line as zero value', function () {
    expect(Clv::versusClosingPrice(-110, -110))->toEqualWithDelta(0.0, 1e-12)
        ->and(Clv::beatTheClose(-110, -110))->toBeFalse();
});

it('values a price in money against the fair close', function () {
    // $100 at even money on something the market closes at 55 percent is
    // worth $10: it wins 55 times in 100 and pays as though it wins 50.
    expect(Clv::expectedValueCents(10000, 100, 0.55))->toBe(1000);
});

it('values a bet at the fair close at nothing', function () {
    // Taking exactly the fair price is worth zero by definition, which is
    // what makes anything above it evidence rather than noise.
    expect(Clv::expectedValueCents(10000, 100, 0.5))->toBe(0);
});

it('reports negative money for a price worse than the close', function () {
    expect(Clv::expectedValueCents(10000, -120, 0.5))->toBeLessThan(0);
});
