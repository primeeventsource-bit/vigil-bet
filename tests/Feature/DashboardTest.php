<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\User;
use App\Models\UserBet;
use App\Models\VlBoardEntry;
use App\Models\VlEvent;
use App\Services\EdgeLab\Mirror;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/*
 * A subscriber, because these tests are about what the dashboard says
 * and not about who may see it. Which parts of the page each tier
 * reaches is EntitlementsTest's question, and a free user here would
 * quietly turn every assertion below into a test of the paywall.
 */
function dashUser(): User
{
    $user = User::forceCreate([
        'name' => 'Jordan Diaz', 'email' => 'jd@example.com',
        'password' => 'correct-horse-battery-staple', 'email_verified_at' => now(),
    ]);

    Subscription::create([
        'user_id' => $user->id, 'tier' => 'pro', 'status' => 'active',
        'current_period_end' => now()->addMonth(), 'source' => 'test',
    ]);

    return $user->refresh();
}

/*
 * The game behind a pick, mirrored too.
 *
 * The dashboard only shows picks on games that have not started, which
 * it can only know by reading the event. A fixture that writes a board
 * entry and no event is a pick on a game that does not exist, and the
 * page is right to drop it.
 */
function dashEvent(int $edgeLabId, array $overrides = []): void
{
    $row = eventRow($edgeLabId, $overrides);

    app(Mirror::class)->write(VlEvent::class, $row, [
        'edge_lab_id' => (int) $row['id'],
        'sport' => $row['sport'],
        'starts_at' => Carbon::parse((string) $row['starts_at']),
        'status' => $row['status'],
        'home_name' => $row['home']['name'],
        'home_abbreviation' => $row['home']['abbreviation'],
        'away_name' => $row['away']['name'],
        'away_abbreviation' => $row['away']['abbreviation'],
    ]);
}

function dashPick(array $overrides = []): VlBoardEntry
{
    $row = boardRow($overrides['id'] ?? 1, array_merge([
        'recommendation' => 'bet', 'market_verdict' => 'proven',
        'model_prob' => 0.76, 'edge_at_price' => 0.124,
    ], $overrides));

    // Kicks off in four hours unless a test says otherwise, because a
    // pick on a game already under way is not one the page may show.
    dashEvent((int) $row['event_id'], $overrides['event'] ?? []);

    app(Mirror::class)->write(VlBoardEntry::class, $row, [
        'edge_lab_id' => (int) $row['id'],
        'edge_lab_event_id' => (int) $row['event_id'],
        'sport' => $row['sport'],
        'market' => $row['market'],
        'side' => $row['side'],
        'line' => $row['line'],
        'selection' => $row['selection'],
        'model_prob' => $row['model_prob'],
        'edge_at_price' => $row['edge_at_price'],
        'best_price_american' => $row['best_price_american'],
        'best_book_title' => $row['best_book_title'],
        'market_verdict' => $row['market_verdict'],
        'recommendation' => $row['recommendation'],
        'reason' => $row['reason'],
    ]);

    return VlBoardEntry::query()->latest('id')->firstOrFail();
}

it('renders the dashboard panels', function () {
    $this->actingAs(dashUser())->get(route('today'))
        ->assertOk()
        ->assertSee('Smarter Bets.')
        ->assertSee('Sharper Edges.')
        ->assertSee('Active Picks')
        ->assertSee('Avg Confidence')
        ->assertSee('Top AI Picks')
        ->assertSee('Bankroll')
        ->assertSee('Live Odds Movement')
        ->assertSee('Model Confidence')
        ->assertSee('Parlay Builder');
});

it('shows the confidence Edge Lab stored, not one of its own', function () {
    dashPick(['model_prob' => 0.76]);

    $this->actingAs(dashUser())->get(route('today'))
        ->assertOk()
        // 76% is the stored model probability under the design's label.
        ->assertSee('76%')
        ->assertSee('+12.4%');
});

it('shows no rate at all before anything has settled', function () {
    dashPick();

    $response = $this->actingAs(dashUser())->get(route('today'))->assertOk();

    // The spec is explicit: no win rate, ROI or units without a sample
    // behind them. Em dashes and n = 0, never a plausible number.
    $response->assertSee('Win Rate')
        ->assertSee('ROI')
        ->assertSee('n = 0')
        ->assertSee('From 0 settled pick(s).');
});

it('puts a sample size beside the confidence tile', function () {
    dashPick();

    $this->actingAs(dashUser())->get(route('today'))->assertOk()->assertSee('n = 1');
});

it('never offers a bet button for an unproven market', function () {
    dashPick(['id' => 1, 'market_verdict' => 'unproven']);

    $this->actingAs(dashUser())->get(route('today'))
        ->assertOk()
        ->assertSee('No proven picks today')
        ->assertDontSee('Add to Bet Slip');
});

it('logs a bet rather than placing one', function () {
    $pick = dashPick();

    $this->actingAs(dashUser())
        ->post(route('bets.store'), ['board_entry_id' => $pick->id, 'stake' => 25])
        ->assertRedirect()
        ->assertSessionHas('status');

    // Nothing was placed and nothing was charged, and the message says so.
    expect(session('status'))->toContain('Nothing was placed or charged');
});

it('refuses to log a bet against an unproven market', function () {
    $pick = dashPick(['market_verdict' => 'negative']);

    $this->actingAs(dashUser())
        ->post(route('bets.store'), ['board_entry_id' => $pick->id, 'stake' => 25])
        ->assertSessionHasErrors('stake');

    expect(UserBet::count())->toBe(0);
});

it('filters the board by sport', function () {
    dashPick(['id' => 1, 'sport' => 'nfl', 'selection' => 'Bills -3.5']);
    dashPick(['id' => 2, 'sport' => 'nba', 'selection' => 'Over 224.5']);

    $this->actingAs(dashUser())->get(route('today', ['sport' => 'nba']))
        ->assertOk()
        ->assertSee('Over 224.5')
        ->assertDontSee('Bills -3.5');
});

it('does not register the demo login outside local', function () {
    // A login bypass. It is fenced by the environment at registration
    // and again when it runs; this proves the first fence.
    expect(app()->environment())->toBe('testing')
        ->and(Route::has('demo.login'))->toBeFalse();
});

/*
 * Two faults from the same screenshot of a live card: every pick listed
 * twice, and games that had finished the day before still sitting there
 * with a Bet button beside them.
 */

it('shows one row per selection however often the board was rescanned', function () {
    // The same selection, judged twice. The board is rescanned hourly
    // and value_bets is append-only, so each scan writes a new row with
    // a new engine id - which `current()` cannot collapse, because it
    // only ever collapses re-syncs of one row.
    // Same game, same selection: boardRow() derives the event from the
    // pick id, so it has to be pinned or these are two different games.
    dashPick(['id' => 1, 'event_id' => 101, 'selection' => 'Philadelphia Eagles']);
    dashPick(['id' => 2, 'event_id' => 101, 'selection' => 'Philadelphia Eagles']);

    $picks = VlBoardEntry::query()->current()->sellablePicks()->beforeKickoff()->get();

    expect($picks)->toHaveCount(1)
        // The newer judgement, not the older one.
        ->and($picks->first()->edge_lab_id)->toBe(2);
});

it('drops a selection the engine has since passed on', function () {
    dashPick(['id' => 1, 'event_id' => 101, 'selection' => 'Philadelphia Eagles']);
    dashPick(['id' => 2, 'event_id' => 101, 'selection' => 'Philadelphia Eagles', 'recommendation' => 'pass']);

    // A newer row of any kind supersedes. The last word was a pass, so
    // there is no pick here any more - showing the earlier bet would be
    // quoting the engine out of date.
    expect(VlBoardEntry::query()->current()->sellablePicks()->beforeKickoff()->count())->toBe(0);
});

it('does not offer a pick on a game that has already started', function () {
    dashPick([
        'id' => 1,
        'selection' => 'Cleveland Browns',
        'event' => ['starts_at' => now()->subHours(3)->toIso8601String(), 'status' => 'final'],
    ]);

    $this->actingAs(dashUser())->get(route('today'))
        ->assertOk()
        ->assertDontSee('Cleveland Browns');
});

it('still offers a pick on a game yet to kick off', function () {
    dashPick([
        'id' => 1,
        'selection' => 'Philadelphia Eagles',
        'event' => ['starts_at' => now()->addHours(2)->toIso8601String()],
    ]);

    $this->actingAs(dashUser())->get(route('today'))
        ->assertOk()
        ->assertSee('Philadelphia Eagles');
});
