<?php

declare(strict_types=1);

use App\Modeling\Ratings\EloRater;

it('gives equal teams a coin flip at a neutral site', function () {
    $elo = new EloRater;

    expect($elo->winProbability(1500, 1500, neutralSite: true))->toEqualWithDelta(0.5, 1e-12);
});

it('gives the home team an edge when equally rated', function () {
    $elo = new EloRater(homeAdvantage: 55.0);

    $probability = $elo->winProbability(1500, 1500);

    expect($probability)->toBeGreaterThan(0.5)
        // 55 points of home advantage is worth about 58 percent.
        ->toEqualWithDelta(0.5786, 0.001);
});

it('matches the textbook four hundred point gap', function () {
    $elo = new EloRater(homeAdvantage: 0.0);

    // A 400 point edge is ten to one in the classic formulation.
    expect($elo->winProbability(1900, 1500))->toEqualWithDelta(10 / 11, 1e-9);
});

it('inverts a probability back to a rating gap', function () {
    $elo = new EloRater;

    foreach ([0.25, 0.5, 0.75, 0.9] as $probability) {
        $difference = $elo->ratingDifferenceFor($probability);

        expect($elo->winProbability(1500 + $difference, 1500, neutralSite: true))
            ->toEqualWithDelta($probability, 1e-9);
    }
});

it('moves ratings toward the winner', function () {
    $elo = new EloRater(kFactor: 20.0, useMarginOfVictory: false);

    $result = $elo->update(1500, 1500, 24, 17);

    expect($result['home'])->toBeGreaterThan(1500)
        ->and($result['away'])->toBeLessThan(1500);
});

it('conserves rating points between the two teams', function () {
    $elo = new EloRater;

    $result = $elo->update(1600, 1450, 30, 10);

    expect($result['home'] + $result['away'])->toEqualWithDelta(1600 + 1450, 1e-9);
});

it('moves ratings more when an upset happens', function () {
    $elo = new EloRater(useMarginOfVictory: false);

    $expectedWin = $elo->update(1700, 1400, 28, 20)['shift'];
    $upset = $elo->update(1400, 1700, 28, 20)['shift'];

    expect($upset)->toBeGreaterThan($expectedWin);
});

it('rewards a blowout more than a narrow win', function () {
    $elo = new EloRater;

    $narrow = $elo->update(1500, 1500, 20, 17)['shift'];
    $blowout = $elo->update(1500, 1500, 45, 10)['shift'];

    expect($blowout)->toBeGreaterThan($narrow);
});

it('damps blowouts so running up the score stops paying', function () {
    $elo = new EloRater;

    $byTwenty = $elo->update(1500, 1500, 40, 20)['shift'];
    $byForty = $elo->update(1500, 1500, 60, 20)['shift'];

    // Twice the margin is worth well under twice the rating move.
    expect($byForty)->toBeLessThan($byTwenty * 1.5);
});

it('moves less when a heavy favourite wins big', function () {
    $elo = new EloRater;

    $favourite = $elo->update(1800, 1400, 45, 10)['shift'];
    $evenMatch = $elo->update(1500, 1500, 45, 10)['shift'];

    expect($favourite)->toBeLessThan($evenMatch);
});

it('splits the difference on a draw', function () {
    $elo = new EloRater(useMarginOfVictory: false);

    // The favourite loses rating by drawing, which is correct.
    $result = $elo->update(1700, 1400, 21, 21);

    expect($result['home'])->toBeLessThan(1700)
        ->and($result['away'])->toBeGreaterThan(1400);
});

it('regresses toward the mean between seasons', function () {
    $elo = new EloRater;

    expect($elo->regressToMean(1800, 0.33))->toEqualWithDelta(1800 - (300 * 0.33), 1e-9)
        ->and($elo->regressToMean(1200, 1.0))->toEqualWithDelta(1500.0, 1e-9)
        ->and($elo->regressToMean(1800, 0.0))->toEqualWithDelta(1800.0, 1e-9);
});

it('round trips its parameters', function () {
    $elo = new EloRater(kFactor: 18.5, homeAdvantage: 42.0);
    $rebuilt = EloRater::fromParams($elo->params());

    expect($rebuilt->kFactor())->toBe(18.5)
        ->and($rebuilt->homeAdvantage())->toBe(42.0);
});

it('rejects a non positive k factor', function () {
    expect(fn () => new EloRater(kFactor: 0.0))->toThrow(InvalidArgumentException::class);
});
