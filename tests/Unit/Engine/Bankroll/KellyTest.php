<?php

declare(strict_types=1);

use App\Modeling\Odds\Kelly;

it('stakes nothing when the model has no edge', function () {
    // 50 percent at -110 is a losing bet; Kelly must say zero, not negative.
    expect(Kelly::full(0.5, -110))->toBe(0.0)
        ->and(Kelly::full(0.45, 100))->toBe(0.0)
        ->and(Kelly::stakeCents(0.5, -110, 0.25, 500000))->toBe(0);
});

it('computes full Kelly for an even money edge', function () {
    // p = 0.55 at +100: f = (1 * 0.55 - 0.45) / 1 = 0.10
    expect(Kelly::full(0.55, 100))->toEqualWithDelta(0.10, 0.000001);
});

it('computes full Kelly against a price with vig', function () {
    // p = 0.55 at -110: b = 0.909091, f = (0.5 - 0.45) / 0.909091 = 0.055
    expect(Kelly::full(0.55, -110))->toEqualWithDelta(0.055, 0.0001);
});

it('scales down by the configured fraction', function () {
    $full = Kelly::full(0.55, 100);

    expect(Kelly::fractional(0.55, 100, 0.25))->toEqualWithDelta($full * 0.25, 0.000001)
        ->and(Kelly::fractional(0.55, 100, 1.0))->toEqualWithDelta($full, 0.000001);
});

it('converts a fraction into whole cents, rounded down', function () {
    // Quarter Kelly on a 10 percent full-Kelly edge, $5,000 bankroll:
    // 0.10 * 0.25 * 500000 = 12500 cents = $125.
    expect(Kelly::stakeCents(0.55, 100, 0.25, 500000))->toBe(12500);
});

it('bets more as the edge grows', function () {
    $small = Kelly::stakeCents(0.53, 100, 0.25, 500000);
    $large = Kelly::stakeCents(0.65, 100, 0.25, 500000);

    expect($large)->toBeGreaterThan($small);
});

it('never stakes against an empty bankroll', function () {
    expect(Kelly::stakeCents(0.8, 100, 0.25, 0))->toBe(0);
});
