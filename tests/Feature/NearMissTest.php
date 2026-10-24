<?php

declare(strict_types=1);

use App\Models\VlBoardEntry;

/*
|--------------------------------------------------------------------------
| Near misses
|--------------------------------------------------------------------------
|
| What the daily card shows while no market is proven, which §4 calls a
| feature rather than an empty state. A list repeating one selection five
| times is not that.
|
*/

/**
 * A board entry, written through the mirror the way a sync writes one.
 *
 * Through Mirror rather than Model::create because these rows carry a
 * payload and a digest that the mirror owns, and a fixture that filled
 * them by hand would be testing a row shape production never produces.
 *
 * A fresh edge_lab_id every time, which is what each scan does - and the
 * reason current() cannot deduplicate this on its own.
 */
function nearMissEntry(array $overrides = []): VlBoardEntry
{
    static $id = 0;
    $id++;

    return entryFor(array_merge([
        'id' => $id,
        'edge_lab_event_id' => 500,
        'sport' => 'ncaaf',
        'market' => 'h2h',
        'side' => 'home',
        'selection' => 'Rutgers Scarlet Knights',
        'edge_at_price' => 0.49,
        'recommendation' => 'pass',
        'pass_reason' => 'market_unproven',
        'market_verdict' => 'insufficient_data',
    ], $overrides));
}

it('shows a selection once, however many scans flagged it', function () {
    /*
     * Five scans of one game produced five board entries and the
     * dashboard listed "Rutgers Scarlet Knights" five times, calling
     * them five near-misses. current() keeps the latest mirror per
     * edge_lab_id, which is correct for a mirror and does nothing here
     * because every scan mints a new id.
     */
    foreach (range(1, 5) as $ignored) {
        nearMissEntry();
    }

    $misses = VlBoardEntry::query()->current()->nearMisses()->get();

    expect($misses)->toHaveCount(1)
        // The newest of them, which carries the newest price.
        ->and($misses->first()->id)->toBe(VlBoardEntry::max('id'));
});

it('keeps genuinely different selections apart', function () {
    nearMissEntry();
    nearMissEntry(['side' => 'away', 'selection' => 'Washington Huskies']);
    nearMissEntry(['market' => 'spreads', 'line' => -3.5]);
    nearMissEntry(['edge_lab_event_id' => 501, 'selection' => 'Oregon Ducks']);

    expect(VlBoardEntry::query()->current()->nearMisses()->get())->toHaveCount(4);
});

it('treats a different spread as a different bet', function () {
    /*
     * The line is part of the identity: -3.5 and -6.5 are not the same
     * wager. The comparison is null-safe, which is also why the
     * moneyline entries above do not collapse into these.
     */
    nearMissEntry(['market' => 'spreads', 'line' => -3.5]);
    nearMissEntry(['market' => 'spreads', 'line' => -6.5]);

    expect(VlBoardEntry::query()->current()->nearMisses()->get())->toHaveCount(2);
});

it('leaves bets out of the near-miss list', function () {
    nearMissEntry(['recommendation' => 'bet', 'edge_lab_event_id' => 502]);

    expect(VlBoardEntry::query()->current()->nearMisses()->get())->toHaveCount(0);
});

it('orders by how close the engine came', function () {
    nearMissEntry(['edge_lab_event_id' => 600, 'edge_at_price' => 0.02]);
    nearMissEntry(['edge_lab_event_id' => 601, 'edge_at_price' => 0.31]);
    nearMissEntry(['edge_lab_event_id' => 602, 'edge_at_price' => 0.12]);

    $edges = VlBoardEntry::query()->current()->nearMisses()->pluck('edge_at_price')
        ->map(fn ($edge) => round((float) $edge, 2))
        ->all();

    expect($edges)->toBe([0.31, 0.12, 0.02]);
});
