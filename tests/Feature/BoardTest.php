<?php

declare(strict_types=1);

use App\Models\VlBoardEntry;
use App\Models\VlEvent;
use App\Models\VlModel;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| §8: the full value board, "with filters: sport, market, book, proven
| only, picks/passes" - and a date, because a board that only shows today
| is useless the morning after.
|--------------------------------------------------------------------------
|
| The thing that matters most here is that the board shows the **passes**.
| Right now every candidate is a pass, and a board that hid those would be
| a blank screen pretending to be an empty one. Five thousand judged
| candidates with a reason attached to each is a product; "no picks today"
| on its own is not.
|
*/

function boardEntryOn(string $sport, string $date, array $overrides = []): VlBoardEntry
{
    static $n = 0;
    $n++;

    $eventId = 3_000 + $n;

    VlEvent::create([
        'edge_lab_id' => $eventId,
        'sport' => $sport,
        'starts_at' => Carbon::parse($date)->setTime(18, 0),
        'status' => 'scheduled',
        'home_name' => ucfirst($sport).' Home '.$n,
        'away_name' => ucfirst($sport).' Away '.$n,
        'payload' => [],
        'digest' => 'e'.$eventId,
        'observed_at' => now(),
    ]);

    return entryFor(array_merge([
        'id' => $n,
        'sport' => $sport,
        'edge_lab_event_id' => $eventId,
        'recommendation' => 'pass',
        'market_verdict' => 'insufficient_data',
        'pass_reason' => 'market_unproven',
        'selection' => ucfirst($sport).' selection '.$n,
    ], $overrides));
}

it('shows every candidate, including the passes', function () {
    boardEntryOn('nfl', '2026-11-01', ['selection' => 'A passed candidate']);

    $this->actingAs(betUser('pro'))->get(route('live-odds'))
        ->assertOk()
        ->assertSee('A passed candidate')
        ->assertSee('Pass')
        // The reason, on the row. A board of passes with no reasons is a
        // wall of "no".
        ->assertSee('market unproven');
});

it('filters by sport', function () {
    boardEntryOn('nfl', '2026-11-01', ['selection' => 'An NFL candidate']);
    boardEntryOn('nba', '2026-11-01', ['selection' => 'An NBA candidate']);

    $this->actingAs(betUser('pro'))->get(route('live-odds', ['sport' => 'nfl']))
        ->assertOk()
        ->assertSee('An NFL candidate')
        ->assertDontSee('An NBA candidate');
});

it('filters by the game date, not the date it was judged', function () {
    boardEntryOn('nfl', '2026-11-01', ['selection' => 'Sunday game']);
    boardEntryOn('nfl', '2026-11-05', ['selection' => 'Thursday game']);

    // A customer choosing Sunday means Sunday's fixtures, and the engine
    // may well have judged them on Friday.
    $this->actingAs(betUser('pro'))->get(route('live-odds', ['date' => '2026-11-01']))
        ->assertOk()
        ->assertSee('Sunday game')
        ->assertDontSee('Thursday game');
});

it('combines a sport and a date', function () {
    boardEntryOn('nfl', '2026-11-01', ['selection' => 'NFL on Sunday']);
    boardEntryOn('nba', '2026-11-01', ['selection' => 'NBA on Sunday']);
    boardEntryOn('nfl', '2026-11-05', ['selection' => 'NFL on Thursday']);

    $this->actingAs(betUser('pro'))
        ->get(route('live-odds', ['sport' => 'nfl', 'date' => '2026-11-01']))
        ->assertOk()
        ->assertSee('NFL on Sunday')
        ->assertDontSee('NBA on Sunday')
        ->assertDontSee('NFL on Thursday');
});

it('offers only dates and sports that have something behind them', function () {
    boardEntryOn('nfl', '2026-11-01');

    $body = $this->actingAs(betUser('pro'))->get(route('live-odds'))->assertOk()->getContent();

    // A filter that offered a date with nothing on it would produce an
    // empty board that looked like a board with nothing on it.
    expect($body)->toContain('2026-11-01')
        ->and($body)->toContain('NFL')
        ->and($body)->not->toContain('2026-11-05');
});

it('ignores a filter value nobody has data for', function () {
    boardEntryOn('nfl', '2026-11-01', ['selection' => 'The only candidate']);

    $this->actingAs(betUser('pro'))
        ->get(route('live-odds', ['sport' => 'cricket', 'date' => '1999-01-01']))
        ->assertOk()
        ->assertSee('The only candidate');
});

it('shows picks only, or passes only, when asked', function () {
    boardEntryOn('nfl', '2026-11-01', ['selection' => 'A pass']);
    boardEntryOn('nfl', '2026-11-01', [
        'selection' => 'A real pick',
        'recommendation' => 'bet',
        'market_verdict' => 'proven',
        'pass_reason' => null,
    ]);

    $this->actingAs(betUser('pro'))->get(route('live-odds', ['show' => 'picks']))
        ->assertOk()
        ->assertSee('A real pick')
        ->assertDontSee('A pass');

    $this->actingAs(betUser('pro'))->get(route('live-odds', ['show' => 'passes']))
        ->assertOk()
        ->assertSee('A pass')
        ->assertDontSee('A real pick');
});

it('explains why nothing is sellable when nothing is', function () {
    VlModel::create([
        'edge_lab_id' => 1, 'sport' => 'nfl', 'market' => 'h2h',
        'model_type' => 'logistic', 'version' => 3, 'status' => 'active',
        'verdict' => 'insufficient_data', 'allows_picks' => false,
        'predictions' => 180, 'log_loss_improvement' => null,
        'gate' => ['min_predictions' => 500], 'trained_at' => now()->subWeek(),
        'payload' => [], 'digest' => 'm1', 'observed_at' => now(),
    ]);

    boardEntryOn('nfl', '2026-11-01');

    /*
     * The concrete answer to "so why is there nothing here", beside the
     * board rather than on a separate page. This is the panel that
     * turns an empty board into a readable one.
     */
    $this->actingAs(betUser('pro'))->get(route('live-odds'))
        ->assertOk()
        ->assertSee('Nothing here is sellable, and here is why')
        ->assertSee('only sells from a market')
        ->assertSee('180 predictions measured')
        ->assertSee('Why each one was declined');
});

it('does not show that panel once something is sellable', function () {
    boardEntryOn('nfl', '2026-11-01', [
        'selection' => 'A real pick',
        'recommendation' => 'bet',
        'market_verdict' => 'proven',
        'pass_reason' => null,
    ]);

    $this->actingAs(betUser('pro'))->get(route('live-odds'))
        ->assertOk()
        ->assertDontSee('Nothing here is sellable');
});

it('offers a bet button only on a sellable pick', function () {
    boardEntryOn('nfl', '2026-11-01', ['selection' => 'A pass']);

    $this->actingAs(betUser('pro'))->get(route('live-odds'))
        ->assertOk()
        ->assertDontSee('name="board_entry_id"', escape: false);

    boardEntryOn('nfl', '2026-11-01', [
        'selection' => 'A real pick',
        'recommendation' => 'bet',
        'market_verdict' => 'proven',
        'pass_reason' => null,
    ]);

    $this->actingAs(betUser('pro'))->get(route('live-odds'))
        ->assertOk()
        ->assertSee('name="board_entry_id"', escape: false);
});

it('serves the board on both names the product uses for it', function () {
    boardEntryOn('nfl', '2026-11-01', ['selection' => 'A candidate']);

    // The design calls the nav item "Live Odds" and the spec calls the
    // page "Board". A customer who clicks either should land on the
    // board, not on two slightly different versions of it.
    foreach (['live-odds', 'picks'] as $route) {
        $this->actingAs(betUser('pro'))->get(route($route))
            ->assertOk()
            ->assertSee('A candidate');
    }
});

it('keeps the board behind pro', function () {
    $this->actingAs(betUser(null))->get(route('live-odds'))
        ->assertRedirect(route('upgrade', ['feature' => 'board.full']));
});

it('reads the board in a flat number of queries', function () {
    foreach (range(1, 12) as $i) {
        boardEntryOn('nfl', '2026-11-01');
    }

    $user = betUser('pro');

    $queries = queriesFor(fn () => $this->actingAs($user)->get(route('live-odds'))->assertOk());

    // Games and Reads are each fetched once for the page, not once per
    // row. A board is the page most likely to grow an N+1.
    expect($queries)->toBeLessThan(25);
});
