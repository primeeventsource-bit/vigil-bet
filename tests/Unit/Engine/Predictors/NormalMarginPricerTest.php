<?php

declare(strict_types=1);

use App\Data\GameProjection;
use App\Enums\Market;
use App\Enums\Side;
use App\Enums\Sport;
use App\Modeling\Predictors\NormalMarginPricer;

function nflProjection(float $home, float $away, float $sigma = 13.5): GameProjection
{
    return new GameProjection(
        sport: Sport::Nfl,
        homeScore: $home,
        awayScore: $away,
        marginSigma: $sigma,
    );
}

beforeEach(fn () => $this->pricer = new NormalMarginPricer);

it('makes an even game a coin flip', function () {
    $prices = $this->pricer->price(nflProjection(23, 23), Market::Moneyline);

    expect($prices->for(Side::Home))->toEqualWithDelta($prices->for(Side::Away), 1e-9);
});

it('favours the better projected team', function () {
    $prices = $this->pricer->price(nflProjection(27, 20), Market::Moneyline);

    expect($prices->for(Side::Home))->toBeGreaterThan(0.6)
        ->and($prices->for(Side::Home))->toBeLessThan(0.75);
});

it('accounts for every outcome including the tie', function () {
    $prices = $this->pricer->price(nflProjection(24, 21), Market::Moneyline);

    expect($prices->sumsToOne())->toBeTrue()
        // NFL ties are rare but real, and should not be handed to a side.
        ->and($prices->push)->toBeGreaterThan(0.0)
        ->and($prices->push)->toBeLessThan(0.05);
});

it('prices a half point spread with no push', function () {
    $prices = $this->pricer->price(nflProjection(24, 21), Market::Spread, -3.5);

    expect($prices->push)->toBe(0.0)
        ->and($prices->for(Side::Home) + $prices->for(Side::Away))->toEqualWithDelta(1.0, 1e-9)
        // Projected to win by 3, laying 3.5: just under a coin flip.
        ->and($prices->for(Side::Home))->toBeLessThan(0.5);
});

it('finds the push sitting on a key number', function () {
    // The mass exactly on 3 is why buying off 3 costs what it does.
    $prices = $this->pricer->price(nflProjection(24, 21), Market::Spread, -3.0);

    expect($prices->push)->toBeGreaterThan(0.02)
        ->and($prices->sumsToOne())->toBeTrue();
});

it('treats a pushed bet as a stake returned, not a loss', function () {
    $prices = $this->pricer->price(nflProjection(24, 21), Market::Spread, -3.0);

    // Conditional on the bet resolving, laying exactly the projected margin
    // is a coin flip.
    expect($prices->excludingPush(Side::Home))->toEqualWithDelta(0.5, 0.02);
});

it('makes a bigger favourite more likely to cover the same number', function () {
    $small = $this->pricer->price(nflProjection(24, 21), Market::Spread, -3.5)->for(Side::Home);
    $large = $this->pricer->price(nflProjection(31, 17), Market::Spread, -3.5)->for(Side::Home);

    expect($large)->toBeGreaterThan($small);
});

it('prices a total around the projected points', function () {
    $prices = $this->pricer->price(nflProjection(24, 21), Market::Total, 44.5);

    // Projected 45 against a 44.5 line: the over is a shade better than even.
    expect($prices->for(Side::Over))->toBeGreaterThan(0.5)
        ->and($prices->for(Side::Over))->toBeLessThan(0.55)
        ->and($prices->for(Side::Over) + $prices->for(Side::Under))->toEqualWithDelta(1.0, 1e-9);
});

it('moves the over as the projected total rises', function () {
    $low = $this->pricer->price(nflProjection(17, 14), Market::Total, 44.5)->for(Side::Over);
    $high = $this->pricer->price(nflProjection(31, 28), Market::Total, 44.5)->for(Side::Over);

    expect($high)->toBeGreaterThan($low);
});

it('widens with sigma, so a volatile sport is less certain', function () {
    $tight = $this->pricer->price(nflProjection(27, 20, 9.0), Market::Moneyline)->for(Side::Home);
    $wide = $this->pricer->price(nflProjection(27, 20, 18.0), Market::Moneyline)->for(Side::Home);

    // The same projected edge is worth less when outcomes scatter more.
    expect($wide)->toBeLessThan($tight);
});

it('defaults the spread to the projected margin', function () {
    $prices = $this->pricer->price(nflProjection(27, 20), Market::Spread);

    expect($prices->line)->toEqualWithDelta(-7.0, 1e-9);
});

it('refuses to price without a sigma', function () {
    $projection = new GameProjection(sport: Sport::Nfl, homeScore: 24, awayScore: 21);

    expect(fn () => $this->pricer->price($projection, Market::Moneyline))
        ->toThrow(InvalidArgumentException::class);
});
