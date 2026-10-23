<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\User;
use App\Models\UserBet;
use App\Models\VlBoardEntry;
use App\Services\EdgeLab\Mirror;
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

function dashPick(array $overrides = []): VlBoardEntry
{
    $row = boardRow($overrides['id'] ?? 1, array_merge([
        'recommendation' => 'bet', 'market_verdict' => 'proven',
        'model_prob' => 0.76, 'edge_at_price' => 0.124,
    ], $overrides));

    app(Mirror::class)->write(VlBoardEntry::class, $row, [
        'edge_lab_id' => (int) $row['id'],
        'sport' => $row['sport'],
        'market' => $row['market'],
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
