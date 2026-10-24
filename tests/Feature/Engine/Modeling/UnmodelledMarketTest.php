<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\ModelType;
use App\Enums\Sport;
use App\Exceptions\UnmodelledMarket;
use App\Models\ModelVersion;
use App\Services\Modeling\BacktestService;

/*
 * The walk-forward scores every prediction on whether the home side won.
 * That is the moneyline's question and nobody else's, and asking it of a
 * spread does not fail - it returns a flattering number, because a spread
 * closes near even money and a moneyline-accurate model beats a coin flip
 * easily on a question the market was never pricing.
 *
 * The first NFL spread run reported beating the close by 0.0741 of log
 * loss. The only thing between that and a recommended bet was the
 * calibration tolerance.
 */

it('refuses totals, because nothing here forecasts points', function () {
    // The projected total is a league constant. A verdict for this market
    // would be measuring a number nobody modelled.
    expect(fn () => app(BacktestService::class)->run(Sport::Nfl, Market::Total))
        ->toThrow(UnmodelledMarket::class);
});

it('accepts spreads, which it can now score', function () {
    // A margin is a restatement of a win probability, and the runner
    // scores it against whether the closing line was covered.
    $result = app(BacktestService::class)->run(Sport::Nfl, Market::Spread);

    expect($result)->toHaveKey('summary');
});

it('still backtests the moneyline, which it can score', function () {
    // No throw. There is no data here, so it refuses for want of games
    // rather than for want of a model - a different and honest reason.
    $result = app(BacktestService::class)->run(Sport::Nfl, Market::Moneyline);

    expect($result)->toHaveKey('summary');
});

it('says what is missing rather than just saying no', function () {
    // Someone reading this has to know whether to wait, fix something, or
    // go and build a model.
    $message = UnmodelledMarket::for(Market::Spread)->getMessage();

    expect($message)->toContain('margin of victory')
        ->toContain('closing line')
        ->and(UnmodelledMarket::for(Market::Total)->getMessage())->toContain('total points');
});

it('stores no model version when it refuses', function () {
    // The danger is a stored row that looks measured. A refusal must leave
    // nothing behind that the board or the internal API could read.
    $this->artisan('edge:train', ['sport' => 'nfl', '--market' => 'totals', '--sync' => true])
        ->expectsOutputToContain('nothing stored')
        ->assertSuccessful();

    expect(ModelVersion::count())->toBe(0);
});

it('does not bring the whole sweep down', function () {
    // Training every sport and market is one command. One unmodellable
    // market must not stop the markets that do work.
    $this->artisan('edge:train', ['sport' => 'nfl', '--market' => 'totals', '--sync' => true])
        ->assertSuccessful();
})->with([ModelType::Elo]);
