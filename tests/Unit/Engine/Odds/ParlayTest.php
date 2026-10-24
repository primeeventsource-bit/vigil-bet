<?php

declare(strict_types=1);

use App\Modeling\Odds\OddsConverter;
use App\Modeling\Odds\Parlay;

it('multiplies leg prices into a combined decimal', function () {
    // Two coin flips at evens pay 4 to 1 together.
    expect(Parlay::decimalPrice([100, 100]))->toBe(4.0)
        ->and(round(Parlay::decimalPrice([-110, -110]), 4))->toBe(round(1.909090909 ** 2, 4));
});

it('states the combined price the way a book would', function () {
    // Three legs at -110 is the classic +595.
    expect(Parlay::americanPrice([-110, -110, -110]))->toBe(596);
});

it('multiplies probabilities for independent legs', function () {
    expect(Parlay::jointProbability([0.5, 0.5]))->toBe(0.25)
        ->and(round(Parlay::jointProbability([0.6, 0.55, 0.52]), 6))->toBe(0.1716);
});

it('never returns a probability outside nought and one', function () {
    expect(Parlay::jointProbability([1.5, 0.5]))->toBe(0.5)
        ->and(Parlay::jointProbability([-0.2, 0.5]))->toBe(0.0);
});

it('compounds the hold with every leg added', function () {
    // A fair coin flip priced at -110 is a 4.5% hold. The same bet twice is
    // not a 4.5% proposition, and this is the number that says so.
    $one = Parlay::hold([0.5], [-110]);
    $two = Parlay::hold([0.5, 0.5], [-110, -110]);
    $four = Parlay::hold([0.5, 0.5, 0.5, 0.5], [-110, -110, -110, -110]);

    expect(round($one, 4))->toBe(0.0455)
        ->and(round($two, 4))->toBe(0.0888)
        ->and(round($four, 4))->toBe(0.1698)
        // Each leg compounds rather than adds.
        ->and($four)->toBeLessThan($one * 4);
});

it('expresses the compounded hold back as a per-leg rate', function () {
    // Four legs holding 17% in total is "like betting four times at 4.5%".
    $hold = Parlay::hold([0.5, 0.5, 0.5, 0.5], [-110, -110, -110, -110]);

    expect(round(Parlay::holdPerLeg([0.5, 0.5, 0.5, 0.5], [-110, -110, -110, -110]), 4))
        ->toBe(0.0455)
        ->and($hold)->toBeGreaterThan(0.17 - 0.001);
});

it('refuses a hold it cannot compute', function () {
    expect(Parlay::hold([0.5], [-110, -110]))->toBeNull()
        ->and(Parlay::hold([], []))->toBeNull()
        // A certainty is not a probability a market can price.
        ->and(Parlay::hold([1.0, 0.5], [-110, -110]))->toBeNull();
});

it('computes expected value against the offered price', function () {
    // A 60% chance at evens returns 20 cents on the dollar.
    expect(round(Parlay::expectedValue(0.6, 2.0), 4))->toBe(0.2)
        ->and(round(Parlay::expectedValue(0.25, 4.0), 4))->toBe(0.0);
});

it('states the rate a parlay must clear to break even', function () {
    expect(Parlay::breakEven(4.0))->toBe(0.25)
        ->and(round(Parlay::breakEven(OddsConverter::americanToDecimal(-110)), 4))->toBe(0.5238);
});

it('shows an edge collapsing once each leg is shaded', function () {
    // Three legs the model likes by two points each. Shade every leg by two
    // points and the joint probability falls by far more than two points,
    // which is the whole risk of a parlay in one number.
    $raw = Parlay::jointProbability([0.58, 0.56, 0.54]);
    $shaded = Parlay::shaded([0.58, 0.56, 0.54], 0.02);

    expect(round($raw, 4))->toBe(0.1754)
        ->and(round($shaded, 4))->toBe(0.1572)
        // Two points off each leg takes a tenth off the joint probability,
        // because the error compounds the same way the price does.
        ->and($raw - $shaded)->toBeGreaterThan(0.018);
});

it('turns a positive edge negative when the shading is honest', function () {
    // Three thin edges, each a point or two over the break-even rate at
    // -110. Shade every leg by two points - no more than a model routinely
    // gets wrong about itself - and the parlay is underwater.
    $price = Parlay::decimalPrice([-110, -110, -110]);
    $legs = [0.54, 0.53, 0.53];

    $confident = Parlay::expectedValue(Parlay::jointProbability($legs), $price);
    $shaded = Parlay::expectedValue(Parlay::shaded($legs, 0.02), $price);

    expect($confident)->toBeGreaterThan(0.0)
        ->and($shaded)->toBeLessThan(0.0);
});

it('never shades a probability below nothing', function () {
    expect(Parlay::shaded([0.01, 0.5], 0.05))->toBe(0.0);
});
