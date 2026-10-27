<?php

declare(strict_types=1);

use App\Enums\Recommendation;
use App\Enums\Verdict;
use App\Jobs\EdgeLab\SyncBoard;
use App\Jobs\EdgeLab\SyncEvents;
use App\Jobs\EdgeLab\SyncOddsDisplay;
use App\Models\Book;
use App\Models\PickResult;
use App\Models\SyncRun;
use App\Models\VlOddsDisplay;
use App\Services\EdgeLab\SyncHealth;
use App\Services\Record\PickPublisher;
use App\Services\Record\PickSettler;

/*
|--------------------------------------------------------------------------
| The prices the product shows
|--------------------------------------------------------------------------
|
| `vl_odds_display` was named in the config and in the staleness table
| from the start and nothing ever wrote to it. Five readers took it as
| their only source of a price - closing line value on the record and on
| a customer's bets, line shopping, the props page, the line-move alerts
| - and all five answered from an empty table without once failing. An
| empty mirror and a quiet night look identical from the outside, which
| is exactly why this needs tests that count rows rather than check that
| nothing threw.
|
*/

beforeEach(function () {
    config()->set('edgelab.enabled', true);

    Book::query()->updateOrCreate(
        ['key' => 'hardrockbet'],
        ['title' => 'Hard Rock Bet', 'is_tracked' => true, 'is_bettable' => true],
    );
});

it('mirrors a price for a game near kickoff', function () {
    $game = engineGame();
    internalQuote($game, 'hardrockbet', -140);

    dispatch_sync(new SyncOddsDisplay);

    $quote = VlOddsDisplay::sole();

    expect($quote->edge_lab_event_id)->toBe($game->id)
        ->and($quote->book)->toBe('hardrockbet')
        ->and($quote->price_american)->toBe(-140)
        // The engine's pull time, not ours. Closing line value asks
        // whether a quote was taken before kickoff, and the honest
        // answer is about when the book said it.
        ->and($quote->quoted_at)->not->toBeNull();
});

it('writes nothing when the price has not moved', function () {
    internalQuote(engineGame(), 'hardrockbet', -140);

    dispatch_sync(new SyncOddsDisplay);
    dispatch_sync(new SyncOddsDisplay);

    // Every ten minutes against thirty-five books would bury the real
    // moves in copies of themselves. The digest is what stops that.
    expect(VlOddsDisplay::count())->toBe(1)
        ->and(SyncRun::query()->feed('odds')->latestFirst()->first()->records_written)->toBe(0);
});

it('writes another row when the price moves', function () {
    $game = engineGame();
    internalQuote($game, 'hardrockbet', -140);

    dispatch_sync(new SyncOddsDisplay);

    internalQuote($game, 'hardrockbet', -165);

    dispatch_sync(new SyncOddsDisplay);

    // Two readings, not one overwritten. Which price was on the board
    // when, is the whole question closing line value asks.
    expect(VlOddsDisplay::count())->toBe(2)
        ->and(VlOddsDisplay::query()->orderByDesc('id')->first()->price_american)->toBe(-165);
});

it('leaves out a book nobody here can bet at', function () {
    $game = engineGame();
    internalQuote($game, 'hardrockbet', -140);
    internalQuote($game, 'somebookie', -150);

    dispatch_sync(new SyncOddsDisplay);

    // A price at a book this product cannot place a bet with is not a
    // price it can offer, and an arbitrage between two of them is not
    // one anybody could take.
    expect(VlOddsDisplay::pluck('book')->all())->toBe(['hardrockbet']);
});

it('keeps the whole tracked field when asked to', function () {
    config()->set('edgelab.odds_mirror.bettable_books_only', false);

    $game = engineGame();
    internalQuote($game, 'hardrockbet', -140);
    internalQuote($game, 'somebookie', -150);

    dispatch_sync(new SyncOddsDisplay);

    expect(VlOddsDisplay::count())->toBe(2);
});

it('ignores a game too far out to be worth pricing', function () {
    internalQuote(engineGame(overrides: ['starts_at' => now()->addDays(30)]), 'hardrockbet', -140);

    dispatch_sync(new SyncOddsDisplay);

    expect(VlOddsDisplay::count())->toBe(0);
});

it('records the run under its own feed, so sync health can see it stop', function () {
    internalQuote(engineGame(), 'hardrockbet', -140);

    dispatch_sync(new SyncOddsDisplay);

    // The feed was in the staleness config from the start with nothing
    // reporting against it, so it could never be called stale.
    expect(SyncRun::query()->feed('odds')->latestFirst()->first()->status)->toBe('success')
        ->and(app(SyncHealth::class)->isStale('odds'))->toBeFalse();
});

/*
 * The payoff, end to end.
 *
 * Closing line value is the figure the product's own copy calls more
 * telling than the win rate at a small sample, because it does not
 * depend on which way a handful of games happened to fall. It was null
 * on every settled pick, and this is the chain that makes it a number.
 */
it('gives the public record a closing line to measure a pick against', function () {
    $game = engineGame();
    engineBoardEntry(null, $game, [
        'market_verdict' => Verdict::Proven->value,
        'recommendation' => Recommendation::Bet->value,
        'pass_reason' => null,
    ]);
    internalQuote($game, 'hardrockbet', -140);

    dispatch_sync(new SyncEvents);
    dispatch_sync(new SyncBoard);
    dispatch_sync(new SyncOddsDisplay);

    app(PickPublisher::class)->run();

    $game->forceFill([
        'status' => 'final',
        'final_home_score' => 27,
        'final_away_score' => 24,
        'completed_at' => now(),
    ])->save();

    dispatch_sync(new SyncEvents);

    app(PickSettler::class)->run();

    expect(PickResult::sole()->closing_price_american)->toBe(-140)
        ->and(PickResult::sole()->clv)->not->toBeNull();
});

/*
 * The failure this job actually had in production.
 *
 * A digest check per quote is the obvious shape and it is wrong by two
 * orders of magnitude: a full slate is thirty-odd games at a hundred and
 * thirty quotes each, every ten minutes, against a database in another
 * region. The job exhausted its attempts on the first real slate it met.
 *
 * So the cost is counted rather than described. What matters is that it
 * does not grow with the number of prices on a game.
 */
it('does not spend a query on every price', function () {
    config()->set('edgelab.odds_mirror.bettable_books_only', false);

    $game = engineGame();

    // Forty books quoting the same game: forty distinct selections, which
    // is the shape of a real board rather than forty prices from one book.
    foreach (range(1, 40) as $n) {
        internalQuote($game, 'book'.$n, -100 - $n);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    dispatch_sync(new SyncOddsDisplay);

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    /*
     * A digest check per quote - the shape this job shipped with - is
     * forty queries here before any write, and on a real slate of thirty
     * games it exhausted the job's attempts against a database in
     * another region. One read of what is held and one insert per game
     * is the contract; the headroom is the feed and book lookups.
     */
    expect($queries)->toBeLessThan(20)
        ->and(VlOddsDisplay::count())->toBe(40);
});
