<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\Side;
use App\Enums\Sport;
use App\Modeling\Predictors\EloProjector;
use App\Modeling\Predictors\NormalMarginPricer;
use App\Modeling\Ratings\EloRater;

beforeEach(fn () => $this->projector = new EloProjector(new EloRater(homeAdvantage: 55.0)));

it('projects an even game as the league baseline', function () {
    $projection = $this->projector->project(Sport::Nfl, 1500, 1500, neutralSite: true);

    expect($projection->margin())->toEqualWithDelta(0.0, 1e-9)
        ->and($projection->total())->toEqualWithDelta(44.0, 1e-9)
        ->and($projection->homeScore)->toEqualWithDelta(22.0, 1e-9);
});

it('converts a rating gap into scoreboard points', function () {
    // 25 Elo points per point in the NFL, so 200 points is eight, plus the
    // home edge of 55 Elo which is another 2.2.
    $projection = $this->projector->project(Sport::Nfl, 1600, 1400);

    expect($projection->margin())->toEqualWithDelta((200 + 55) / 25, 1e-9);
});

it('drops the home edge at a neutral site', function () {
    $home = $this->projector->project(Sport::Nfl, 1550, 1500)->margin();
    $neutral = $this->projector->project(Sport::Nfl, 1550, 1500, neutralSite: true)->margin();

    expect($home)->toBeGreaterThan($neutral)
        ->and($home - $neutral)->toEqualWithDelta(55 / 25, 1e-9);
});

it('converts far more slowly in low scoring sports', function () {
    $football = $this->projector->project(Sport::Nfl, 1600, 1500, neutralSite: true)->margin();
    $hockey = $this->projector->project(Sport::Nhl, 1600, 1500, neutralSite: true)->margin();

    // The same rating gap is four points of football and under a goal.
    expect($football)->toBeGreaterThan(3.0)
        ->and($hockey)->toBeLessThan(1.0);
});

it('never projects a negative scoring rate', function () {
    // A rating gap this absurd would push the weaker side below zero goals
    // if nothing stopped it, and a negative rate breaks every count model.
    $projection = $this->projector->project(Sport::Nhl, 2600, 1200, neutralSite: true);

    expect($projection->awayScore)->toBeGreaterThan(0.0)
        ->and($projection->homeScore)->toBeGreaterThan(0.0);
});

it('carries the per sport distribution parameters', function () {
    expect($this->projector->project(Sport::Nfl, 1500, 1500)->marginSigma)->toEqualWithDelta(13.5, 1e-9)
        ->and($this->projector->project(Sport::Mlb, 1500, 1500)->dispersion)->toEqualWithDelta(4.0, 1e-9)
        ->and($this->projector->project(Sport::Nhl, 1500, 1500)->dispersion)->toBeNull();
});

it('shows its working', function () {
    $projection = $this->projector->project(Sport::Nfl, 1600, 1450);

    expect($projection->components)->toHaveKeys([
        'home_rating', 'away_rating', 'home_advantage', 'rating_gap', 'projected_margin',
    ])->and($projection->components['rating_gap'])->toEqualWithDelta(205.0, 1e-9);
});

it('accepts a total override, because Elo never knew about scoring', function () {
    // Elo only ever learned margins. A real total has to come from
    // somewhere else, so the baseline is a placeholder, not a prediction.
    $projection = $this->projector->project(Sport::Nfl, 1550, 1500, totalOverride: 51.5);

    expect($projection->total())->toEqualWithDelta(51.5, 1e-9);
});

it('disagrees with the raw Elo win probability, on purpose', function () {
    // Two different models of the same game. Elo converts a rating gap with
    // a logistic curve; the margin model converts it through a normal
    // distribution with a sport specific sigma. On a big favourite they
    // differ by several points of probability, which is a lot of money.
    //
    // Neither is assumed correct here. Both get backtested against the
    // closing line and the data decides which one, if either, is allowed to
    // produce a pick.
    $elo = $this->projector->winProbability(1650, 1450);
    $priced = (new NormalMarginPricer)
        ->price($this->projector->project(Sport::Nfl, 1650, 1450), Market::Moneyline)
        ->for(Side::Home);

    expect($elo)->toBeGreaterThan($priced)
        ->and($elo - $priced)->toBeGreaterThan(0.02)
        // Both still agree on who is favoured and that it is not a lock.
        ->and($priced)->toBeGreaterThan(0.7)
        ->and($elo)->toBeLessThan(0.9);
});
