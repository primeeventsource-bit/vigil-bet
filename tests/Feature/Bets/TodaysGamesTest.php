<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Today's games
|--------------------------------------------------------------------------
|
| The page that exists because the parlay builder had nothing to build
| from. It took its legs from the engine's board, and the board only
| offered picks from markets the engine had proven - of 24,610 candidates
| evaluated, none. So the builder rendered an empty list and refused every
| click, which looked like a broken front end and was a deliberate
| restriction working exactly as written.
|
| These tests are about the separation that fixes it: the engine's
| standards govern what VIGIL publishes, and nothing else governs what a
| customer may record.
|
*/

use App\Models\Event;
use App\Models\Team;
use App\Services\Parlay\ParlaySlip;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('ai.enabled', false);
    config()->set('edgelab.enabled', true);
});

function unpricedGame(string $home = 'Nobody Quotes This', ?CarbonInterface $at = null): Event
{
    $salt = Str::random(6);

    return Event::create([
        'sport' => 'nfl',
        'home_team_id' => Team::create([
            'sport' => 'nfl', 'name' => $home, 'display_name' => $home, 'slug' => 'h-'.$salt,
        ])->id,
        'away_team_id' => Team::create([
            'sport' => 'nfl', 'name' => 'Away '.$salt, 'display_name' => 'Away '.$salt, 'slug' => 'a-'.$salt,
        ])->id,
        'starts_at' => $at ?? now()->addDays(2),
        'season' => (int) now()->year,
        'status' => 'scheduled',
    ]);
}
it('lists a game the books are pricing', function () {
    $selection = pricedSelection(['home' => 'Baltimore Ravens', 'away' => 'Cincinnati Bengals']);

    $this->actingAs(betUser())->get(route('games'))
        ->assertOk()
        ->assertSee('Baltimore Ravens')
        ->assertSee('Cincinnati Bengals');

    expect($selection['event_id'])->toBeInt();
});

it('lists a game the engine refused, which is the whole point', function () {
    /*
     * The board entry says `pass` on an unproven market - the state all
     * 24,610 candidates are in. The game is still bettable and still
     * appears.
     */
    $selection = pricedSelection(['home' => 'Refused By Engine']);

    provenEntry([
        'id' => 4242,
        'edge_lab_event_id' => $selection['event_id'],
        'market_verdict' => 'unproven',
        'recommendation' => 'pass',
    ]);

    $this->actingAs(betUser())->get(route('games'))
        ->assertOk()
        ->assertSee('Refused By Engine');
});

it('does not show a game with no price, because a row you cannot click looks broken', function () {
    /*
     * The game exists and is ahead of us; nobody is quoting it. Dropped
     * rather than rendered empty - a fixture you cannot click is
     * indistinguishable from a fixture that is broken.
     */
    unpricedGame();

    $this->actingAs(betUser())->get(route('games'))
        ->assertOk()
        ->assertSee('No priced markets');
});

it('does not show a game that has already started', function () {
    /*
     * A price on a game already under way is not an offer, and taking a
     * bet on it would be recording something nobody could have placed.
     */
    $started = unpricedGame('Already Kicked Off', now()->subHour());

    pricedSelection(['event' => $started]);

    $this->actingAs(betUser())->get(route('games'))
        ->assertOk()
        ->assertDontSee('Already Kicked Off');
});

it('adds a selection to the slip when the market is clicked', function () {
    $user = betUser();
    $selection = pricedSelection(['price_american' => -120]);

    $this->actingAs($user)->post(route('parlays.add'), $selection)
        ->assertSessionHasNoErrors();

    $slip = app(ParlaySlip::class)->selections();

    expect($slip)->toHaveCount(1)
        // The price came from our own table, not from the form.
        ->and($slip->first()['price_american'])->toBe(-120);
});

it('ignores a price posted in the form, because a form field is the customer’s to edit', function () {
    $user = betUser();
    $selection = pricedSelection(['price_american' => -110]);

    $this->actingAs($user)->post(route('parlays.add'), $selection + [
        'price_american' => 50000,
        'selection' => 'Whatever I typed',
    ])->assertSessionHasNoErrors();

    $leg = app(ParlaySlip::class)->selections()->first();

    expect($leg['price_american'])->toBe(-110)
        ->and($leg['selection'])->not->toBe('Whatever I typed');
});

it('shows a taken selection as taken', function () {
    $user = betUser();
    $selection = pricedSelection(['home' => 'On My Slip']);

    $this->actingAs($user)->post(route('parlays.add'), $selection);

    /*
     * The button becomes a remove button, so the same control both takes
     * a selection and gives it back - which is what "click again to
     * deselect" means.
     */
    $this->actingAs($user)->get(route('games'))
        ->assertOk()
        ->assertSee('aria-pressed="true"', false);
});

it('builds a combined price from two selections', function () {
    $user = betUser();

    $this->actingAs($user)->post(route('parlays.add'), pricedSelection(['price_american' => 100]));
    $this->actingAs($user)->post(route('parlays.add'), pricedSelection(['price_american' => 100]));

    // Two even-money legs are decimal 4.0 together, which is +300.
    expect(app(ParlaySlip::class)->combinedPriceAmerican())->toBe(300);
});

it('estimates a return from a stake at the combined price', function () {
    $user = betUser();

    $this->actingAs($user)->post(route('parlays.add'), pricedSelection(['price_american' => 100]));
    $this->actingAs($user)->post(route('parlays.add'), pricedSelection(['price_american' => 100]));

    // $100 at +300 returns $400 including the stake.
    expect(app(ParlaySlip::class)->estimatedReturnCents(10000))->toBe(40000);
});

it('offers a totals market with its line, since Over 44.5 is not Over 47.5', function () {
    $user = betUser();

    $over = pricedSelection(['market' => 'totals', 'side' => 'over', 'line' => 44.5]);

    $this->actingAs($user)->post(route('parlays.add'), $over)->assertSessionHasNoErrors();

    $leg = app(ParlaySlip::class)->selections()->first();

    expect($leg['line'])->toBe(44.5)
        ->and($leg['selection'])->toContain('Over 44.5');
});

it('requires a login, because a slip belongs to somebody', function () {
    $this->get(route('games'))->assertRedirect();
});
