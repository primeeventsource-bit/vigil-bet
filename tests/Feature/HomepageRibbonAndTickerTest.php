<?php

declare(strict_types=1);

use App\Support\Clock;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The two bands the reference layout puts under the hero
|--------------------------------------------------------------------------
|
| A callout ribbon and a live odds strip. Both were drawn with invented
| content - the ribbon promised Telegram steam alerts, the ticker showed
| Bills vs Chiefs at -3.5 (-110) and dated itself "Updated 12s ago" - so
| what these tests hold is the line between the layout and the data.
|
| The ribbon may only name things that exist. The ticker may only show
| games that exist, and when there are none it may not show at all: an
| empty live feed reads as a broken one, and the homepage already has a
| real absence to explain without acquiring a decorative second.
|
*/

/* ----------------------------------------------------------------------
 | The ribbon
 ---------------------------------------------------------------------- */

it('names five things the product actually does', function (string $heading) {
    $this->get(route('home'))->assertOk()->assertSee($heading);
})->with([
    'AI predictions',
    'Real-time odds',
    'Track your bets',
    'Build parlays',
    'Data-driven edge',
]);

it('promises nothing the product cannot do', function () {
    // The reference ribbon's sixth callout was instant Telegram steam
    // alerts. There is no Telegram integration and there never was.
    $page = $this->get(route('home'))->assertOk()->getContent();

    expect($page)->not->toContain('Telegram')
        ->and($page)->not->toContain('steam');
});

/* ----------------------------------------------------------------------
 | The ticker, when there is nothing to put in it
 ---------------------------------------------------------------------- */

it('shows no odds band at all when no game has a price', function () {
    // Which is the state the site was in when this was built: eight
    // thousand scheduled fixtures and nothing inside the window with a
    // quote fresh enough to stand behind.
    $page = $this->get(route('home'))->assertOk()->getContent();

    expect($page)->not->toContain('Live odds feed')
        ->and($page)->not->toContain('Best available price');
});

it('shows no odds band when the only prices are stale', function () {
    $event = engineGame('Buffalo Bills', 'Miami Dolphins', ['starts_at' => now()->addHours(5)]);
    pricedSelection(['event' => $event, 'side' => 'home', 'price_american' => -130]);

    // Older than GameMarkets considers fresh. A price from two days ago
    // is not an offer, and showing it under a pulsing green dot would
    // be the single most dishonest thing on the page.
    DB::table('odds_snapshots')->update(['pulled_at' => now()->subDays(2)]);

    $this->get(route('home'))->assertOk()->assertDontSee('Live odds feed');
});

/* ----------------------------------------------------------------------
 | The ticker, with real games in it
 ---------------------------------------------------------------------- */

it('shows a real upcoming game at the price the books are posting', function () {
    $event = engineGame('Buffalo Bills', 'Miami Dolphins', ['starts_at' => now()->addHours(5)]);

    pricedSelection(['event' => $event, 'side' => 'home', 'price_american' => -130]);
    pricedSelection(['event' => $event, 'side' => 'away', 'price_american' => 115]);

    $response = $this->get(route('home'))->assertOk();

    $response->assertSee('Live odds feed')
        ->assertSee('Buffalo Bills')
        ->assertSee('Miami Dolphins')
        // Signed, both ways. An American price without its sign is a
        // different price.
        ->assertSee('-130')
        ->assertSee('+115');
});

it('reads the kickoff on the clock the product is read on', function () {
    // Every instant is stored UTC. A template that formats the column
    // straight renders a perfectly typeset wrong time, which is the
    // failure ShowsLocalTimeTest exists for - this is the same rule
    // applied to the band it does not cover.
    $kickoff = now()->addHours(6);

    $event = engineGame('Buffalo Bills', 'Miami Dolphins', ['starts_at' => $kickoff]);
    pricedSelection(['event' => $event, 'side' => 'home', 'price_american' => -130]);

    $this->get(route('home'))->assertOk()
        ->assertSee(Clock::format($kickoff, 'D g:i A'));
});

it('dates the strip from the data rather than from the layout', function () {
    $event = engineGame('Buffalo Bills', 'Miami Dolphins', ['starts_at' => now()->addHours(5)]);
    pricedSelection(['event' => $event, 'side' => 'home', 'price_american' => -130]);

    $page = $this->get(route('home'))->assertOk()->getContent();

    expect($page)->toContain('Prices pulled')
        // The reference wrote this into the markup, where it was wrong
        // one second after it was written.
        ->and($page)->not->toContain('Updated 12s ago');
});

it('says the prices are not a recommendation', function () {
    $event = engineGame('Buffalo Bills', 'Miami Dolphins', ['starts_at' => now()->addHours(5)]);
    pricedSelection(['event' => $event, 'side' => 'home', 'price_american' => -130]);

    $this->get(route('home'))->assertOk()->assertSee('Not a pick.');
});

it('leaves a game out of the band entirely when it has no moneyline', function () {
    $event = engineGame('Buffalo Bills', 'Miami Dolphins', ['starts_at' => now()->addHours(5)]);

    // Totals only. The band shows two moneyline prices per row, and a
    // row with nothing in its price column is a broken-looking row.
    pricedSelection(['event' => $event, 'market' => 'totals', 'side' => 'over', 'line' => 44.5]);

    $this->get(route('home'))->assertOk()->assertDontSee('Live odds feed');
});
