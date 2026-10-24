<?php

declare(strict_types=1);

use App\Data\GameProjection;
use App\Enums\Market;
use App\Enums\Side;
use App\Enums\Sport;
use App\Modeling\Predictors\NegativeBinomialPricer;
use App\Modeling\Predictors\PoissonPricer;

function nhlProjection(float $home, float $away): GameProjection
{
    return new GameProjection(sport: Sport::Nhl, homeScore: $home, awayScore: $away);
}

function mlbProjection(float $home, float $away, ?float $dispersion = 4.0): GameProjection
{
    return new GameProjection(sport: Sport::Mlb, homeScore: $home, awayScore: $away, dispersion: $dispersion);
}

it('splits a hockey tie into overtime rather than refunding it', function () {
    $prices = (new PoissonPricer)->price(nhlProjection(3.0, 3.0), Market::Moneyline);

    // A regulation tie is decided in overtime, so nothing is pushed.
    expect($prices->push)->toBe(0.0)
        ->and($prices->for(Side::Home) + $prices->for(Side::Away))->toEqualWithDelta(1.0, 1e-9)
        ->and($prices->for(Side::Home))->toEqualWithDelta(0.5, 1e-9);
});

it('keeps hockey moneylines close, the way the sport actually plays', function () {
    $prices = (new PoissonPricer)->price(nhlProjection(3.3, 2.7), Market::Moneyline);

    // A six tenths of a goal edge is a real favourite but nowhere near a
    // football-sized one. This is why hockey prices cluster near even.
    expect($prices->for(Side::Home))->toBeGreaterThan(0.55)
        ->and($prices->for(Side::Home))->toBeLessThan(0.65);
});

it('tilts the overtime split toward the better team', function () {
    $even = (new PoissonPricer)->price(nhlProjection(3.0, 3.0), Market::Moneyline)->for(Side::Home);
    $better = (new PoissonPricer)->price(nhlProjection(3.4, 2.6), Market::Moneyline)->for(Side::Home);

    expect($better)->toBeGreaterThan($even);
});

it('prices a hockey total with its push', function () {
    $prices = (new PoissonPricer)->price(nhlProjection(3.0, 3.0), Market::Total, 6.0);

    expect($prices->push)->toBeGreaterThan(0.1)
        ->and($prices->sumsToOne())->toBeTrue();
});

it('prices the puck line by enumerating goals', function () {
    $prices = (new PoissonPricer)->price(nhlProjection(3.2, 2.8), Market::Spread, -1.5);

    // Winning by two or more is a lot to ask of a small favourite.
    expect($prices->for(Side::Home))->toBeLessThan(0.45)
        ->and($prices->sumsToOne())->toBeTrue();
});

it('gives baseball more total variance than Poisson would', function () {
    $overdispersed = (new NegativeBinomialPricer)
        ->price(mlbProjection(4.5, 4.5, 4.0), Market::Total, 12.5)
        ->for(Side::Over);

    $poissonLike = (new NegativeBinomialPricer)
        ->price(mlbProjection(4.5, 4.5, null), Market::Total, 12.5)
        ->for(Side::Over);

    // A twelve run game is rare, not nearly impossible. Pricing it with
    // Poisson is how a totals model quietly sells overs it should buy.
    expect($overdispersed)->toBeGreaterThan($poissonLike);
});

it('prices a baseball moneyline with no ties', function () {
    $prices = (new NegativeBinomialPricer)->price(mlbProjection(4.8, 4.3), Market::Moneyline);

    expect($prices->push)->toBe(0.0)
        ->and($prices->for(Side::Home) + $prices->for(Side::Away))->toEqualWithDelta(1.0, 1e-9)
        ->and($prices->for(Side::Home))->toBeGreaterThan(0.5)
        // Baseball favourites are small favourites.
        ->and($prices->for(Side::Home))->toBeLessThan(0.62);
});

it('prices the run line', function () {
    $prices = (new NegativeBinomialPricer)->price(mlbProjection(5.0, 4.0), Market::Spread, -1.5);

    expect($prices->for(Side::Home))->toBeLessThan(0.5)
        ->and($prices->sumsToOne())->toBeTrue();
});

it('falls back to Poisson when no dispersion is known', function () {
    $prices = (new NegativeBinomialPricer)->price(mlbProjection(4.5, 4.5, null), Market::Moneyline);

    expect($prices->for(Side::Home))->toEqualWithDelta(0.5, 1e-9)
        ->and($prices->sumsToOne())->toBeTrue();
});

it('raises the over as run expectations rise', function () {
    $low = (new NegativeBinomialPricer)->price(mlbProjection(3.5, 3.5), Market::Total, 8.5)->for(Side::Over);
    $high = (new NegativeBinomialPricer)->price(mlbProjection(5.5, 5.5), Market::Total, 8.5)->for(Side::Over);

    expect($high)->toBeGreaterThan($low);
});
