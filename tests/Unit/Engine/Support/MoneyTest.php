<?php

declare(strict_types=1);

use App\Support\Money;

it('converts dollars to integer cents without float drift', function () {
    expect(Money::fromDollars(10.10))->toBe(1010)
        ->and(Money::fromDollars(0.07))->toBe(7)
        ->and(Money::fromDollars(1234.56))->toBe(123456)
        ->and(Money::fromDollars('19.99'))->toBe(1999);
});

it('formats cents for display', function () {
    expect(Money::format(123456))->toBe('$1,234.56')
        ->and(Money::format(-2500))->toBe('-$25.00')
        ->and(Money::format(2500, signed: true))->toBe('+$25.00')
        ->and(Money::format(0))->toBe('$0.00');
});

it('expresses a stake in units', function () {
    // A $25 stake against a $10 unit is two and a half units.
    expect(Money::toUnits(2500, 1000))->toBe(2.5)
        ->and(Money::toUnits(500, 1000))->toBe(0.5)
        ->and(Money::fromUnits(1.5, 1000))->toBe(1500);
});

it('never divides by a zero unit size', function () {
    expect(Money::toUnits(2500, 0))->toBe(0.0);
});

it('takes a percentage of a bankroll, rounding down', function () {
    // Two percent of $1,000 is $20. Rounding down keeps stakes under caps.
    expect(Money::pctOf(100000, 2.0))->toBe(2000)
        ->and(Money::pctOf(100033, 2.0))->toBe(2000);
});
