<?php

declare(strict_types=1);

use App\Modeling\Odds\OddsConverter;

it('converts american favourites to decimal', function () {
    expect(OddsConverter::americanToDecimal(-110))->toEqualWithDelta(1.909091, 0.000001)
        ->and(OddsConverter::americanToDecimal(-200))->toBe(1.5)
        ->and(OddsConverter::americanToDecimal(-100))->toBe(2.0);
});

it('converts american underdogs to decimal', function () {
    expect(OddsConverter::americanToDecimal(150))->toBe(2.5)
        ->and(OddsConverter::americanToDecimal(100))->toBe(2.0)
        ->and(OddsConverter::americanToDecimal(275))->toBe(3.75);
});

it('round trips american to decimal and back', function (int $american) {
    expect(OddsConverter::decimalToAmerican(OddsConverter::americanToDecimal($american)))
        ->toBe($american);
})->with([-500, -300, -189, -110, -105, 100, 110, 145, 320, 900]);

it('normalises even money to plus one hundred', function () {
    // -100 and +100 are the same price. Decimal 2.0 round trips to the
    // conventional +100, which is what gets stored.
    expect(OddsConverter::americanToDecimal(-100))->toBe(2.0)
        ->and(OddsConverter::decimalToAmerican(2.0))->toBe(100);
});

it('rejects impossible american odds', function (int $american) {
    expect(fn () => OddsConverter::americanToDecimal($american))
        ->toThrow(InvalidArgumentException::class);
})->with([-99, 0, 1, 99]);

it('derives implied probability including the vig', function () {
    expect(OddsConverter::americanToProbability(-110))->toEqualWithDelta(0.523810, 0.000001)
        ->and(OddsConverter::americanToProbability(100))->toBe(0.5)
        ->and(OddsConverter::americanToProbability(200))->toEqualWithDelta(0.333333, 0.000001);
});

it('shows a standard two way market overrounds', function () {
    $total = OddsConverter::americanToProbability(-110) + OddsConverter::americanToProbability(-110);

    expect($total)->toBeGreaterThan(1.0)->toEqualWithDelta(1.047619, 0.000001);
});

it('converts a probability to its fair price', function () {
    expect(OddsConverter::probabilityToAmerican(0.5))->toBe(100)
        ->and(OddsConverter::probabilityToAmerican(0.6))->toBe(-150)
        ->and(OddsConverter::probabilityToAmerican(0.25))->toBe(300);
});

it('computes expected value per dollar staked', function () {
    // A coin flip paid at +110 is worth five cents on the dollar.
    expect(OddsConverter::expectedValue(0.5, 110))->toEqualWithDelta(0.05, 0.0000000001)
        // A coin flip paid at -110 loses about four and a half cents.
        ->and(OddsConverter::expectedValue(0.5, -110))->toEqualWithDelta(-0.045455, 0.000001)
        ->and(OddsConverter::expectedValue(0.55, -110))->toEqualWithDelta(0.05, 0.0001);
});

it('finds the worst price that still clears the required edge', function () {
    // Model says 55 percent. Demanding a 3 point edge means the implied
    // probability may not exceed 52 percent, which is about -108.
    $cutoff = OddsConverter::minimumAcceptableAmerican(0.55, 0.03);

    expect(OddsConverter::americanToProbability($cutoff))->toEqualWithDelta(0.52, 0.001)
        ->and($cutoff)->toBe(-108);
});

it('treats the cutoff price as exactly break even on edge', function () {
    $modelProb = 0.62;
    $edge = 0.04;

    $cutoff = OddsConverter::minimumAcceptableAmerican($modelProb, $edge);
    $impliedAtCutoff = OddsConverter::americanToProbability($cutoff);

    expect($modelProb - $impliedAtCutoff)->toBeGreaterThanOrEqual($edge - 0.005);
});

it('refuses a cutoff when the required edge exceeds the model probability', function () {
    expect(fn () => OddsConverter::minimumAcceptableAmerican(0.03, 0.05))
        ->toThrow(InvalidArgumentException::class);
});

it('formats american odds the way a book prints them', function () {
    expect(OddsConverter::formatAmerican(150))->toBe('+150')
        ->and(OddsConverter::formatAmerican(-110))->toBe('-110');
});
