<?php

declare(strict_types=1);

use App\Models\PickResult;
use App\Models\PublishedPick;
use App\Services\Record\PublicRecord;

function publishedPick(array $overrides = []): PublishedPick
{
    static $n = 0;
    $n++;

    return PublishedPick::create(array_merge([
        'edge_lab_event_id' => 1_000 + $n,
        'sport' => 'nfl',
        'market' => 'h2h',
        'side' => 'home',
        'selection' => 'Pick '.$n,
        'matchup' => 'Bills at Chiefs',
        'price_american' => -110,
        'book' => 'draftkings',
        'book_title' => 'DraftKings',
        'model_prob' => 0.58,
        'fair_prob' => 0.55,
        'edge_at_price' => 0.03,
        'stake_fraction' => 0.012,
        'model_version' => 4,
        'kickoff_at' => now()->addHours(3),
        'published_at' => now()->subMinutes($n),
        'created_at' => now(),
    ], $overrides));
}

function resultFor(PublishedPick $pick, string $status, float $units, ?float $clv = null): PickResult
{
    return PickResult::create([
        'published_pick_id' => $pick->id,
        'status' => $status,
        'units' => $units,
        'clv' => $clv,
        'settled_at' => now(),
        'created_at' => now(),
    ]);
}

/*
|--------------------------------------------------------------------------
| §7: "always with sample size. With zero settled picks, show no rate at
| all."
|--------------------------------------------------------------------------
*/

it('shows no rate at all with nothing settled', function () {
    $summary = app(PublicRecord::class)->summary();

    // Null, not zero. A 0% ROI and an unmeasured ROI look identical on a
    // page and mean entirely different things.
    expect($summary['win_rate'])->toBeNull()
        ->and($summary['roi'])->toBeNull()
        ->and($summary['units'])->toBeNull()
        ->and($summary['clv'])->toBeNull()
        ->and($summary['settled'])->toBe(0);
});

it('says why the record is empty rather than showing zeros', function () {
    $this->get(route('record'))
        ->assertOk()
        ->assertSee('No market has cleared the gate')
        // The reason, which is a fact about the gate and not about the
        // data: the figures come from real inputs, and what is missing
        // is a market that has met a harder bar.
        ->assertSee('beaten the closing line')
        ->assertSee('comes from real data', false);
});

it('counts a push toward the sample but not toward the win rate', function () {
    resultFor(publishedPick(), 'won', 0.91);
    resultFor(publishedPick(), 'lost', -1.0);
    resultFor(publishedPick(), 'push', 0.0);

    $summary = app(PublicRecord::class)->summary();

    expect($summary['settled'])->toBe(3)
        // One win from two decided bets. A push is not half a win.
        ->and($summary['win_rate'])->toBe(0.5)
        ->and($summary['pushed'])->toBe(1);
});

it('keeps a void out of the record entirely', function () {
    resultFor(publishedPick(), 'won', 0.91);
    resultFor(publishedPick(), 'void', 0.0);

    $summary = app(PublicRecord::class)->summary();

    // A game that never ran is not a result, and counting it would
    // dilute the record with nothing.
    expect($summary['settled'])->toBe(1)
        ->and($summary['win_rate'])->toBe(1.0);
});

it('reports roi as units per pick, on flat stakes', function () {
    resultFor(publishedPick(), 'won', 0.91);
    resultFor(publishedPick(), 'lost', -1.0);

    $summary = app(PublicRecord::class)->summary();

    // -0.09 units over two picks. Staking to the suggested fraction
    // would be a different, better-looking number no reader followed.
    expect($summary['units'])->toBe(-0.09)
        ->and($summary['roi'])->toBe(-0.045);
});

it('averages closing line value only over picks that have one', function () {
    resultFor(publishedPick(), 'won', 0.91, clv: 0.02);
    resultFor(publishedPick(), 'lost', -1.0, clv: 0.04);
    // No CLV: no stored quote before kickoff.
    resultFor(publishedPick(), 'lost', -1.0);

    $summary = app(PublicRecord::class)->summary();

    // Counting the third as zero would drag a real figure toward
    // nothing.
    expect($summary['clv'])->toBe(0.03)
        ->and($summary['clv_sample'])->toBe(2);
});

/* ----------------------------------------------------------------------
 | The page itself
 ---------------------------------------------------------------------- */

it('shows the losers in the same table as the winners', function () {
    resultFor($won = publishedPick(['selection' => 'A winner']), 'won', 0.91);
    resultFor($lost = publishedPick(['selection' => 'A loser']), 'lost', -1.0);
    publishedPick(['selection' => 'Still running']);

    $this->get(route('record'))
        ->assertOk()
        // A record page that showed only winners would be an
        // advertisement, and §7 asks for the opposite of one.
        ->assertSee('A winner')
        ->assertSee('A loser')
        ->assertSee('Still running')
        ->assertSee('Running');
});

it('is public and indexable', function () {
    resultFor(publishedPick(), 'won', 0.91);

    // No login, and no noindex: the only page on the site a sceptic will
    // take seriously has to be reachable by one. The head partial
    // defaults to noindex and marketing pages opt out of it, so the
    // assertion is the absence of the tag.
    $this->get(route('record'))
        ->assertOk()
        ->assertDontSee('noindex');

    $this->get(route('record.pick', PublishedPick::query()->sole()))
        ->assertOk()
        ->assertDontSee('noindex');
});

it('filters by sport, market and month', function () {
    resultFor(publishedPick(['sport' => 'nfl', 'market' => 'h2h', 'selection' => 'An NFL moneyline']), 'won', 0.91);
    resultFor(publishedPick(['sport' => 'nba', 'market' => 'totals', 'selection' => 'An NBA total']), 'lost', -1.0);

    $this->get(route('record', ['sport' => 'nfl']))
        ->assertOk()
        ->assertSee('An NFL moneyline')
        ->assertDontSee('An NBA total');

    $this->get(route('record', ['market' => 'totals']))
        ->assertOk()
        ->assertSee('An NBA total')
        ->assertDontSee('An NFL moneyline');

    $this->get(route('record', ['month' => now()->format('Y-m')]))
        ->assertOk()
        ->assertSee('An NFL moneyline');
});

it('ignores a filter value nobody published in', function () {
    resultFor(publishedPick(['sport' => 'nfl', 'selection' => 'An NFL pick']), 'won', 0.91);

    // A month nobody published in would produce an empty page that
    // looked like a record of nothing.
    $this->get(route('record', ['sport' => 'cricket', 'month' => '1999-01']))
        ->assertOk()
        ->assertSee('An NFL pick');
});

it('recomputes the summary for the filtered set', function () {
    resultFor(publishedPick(['sport' => 'nfl']), 'won', 0.91);
    resultFor(publishedPick(['sport' => 'nba']), 'lost', -1.0);

    $nfl = app(PublicRecord::class)->summary(['sport' => 'nfl']);

    // Otherwise a reader filtering to one sport would see the headline
    // figures for all of them.
    expect($nfl['settled'])->toBe(1)
        ->and($nfl['win_rate'])->toBe(1.0)
        ->and($nfl['units'])->toBe(0.91);
});

/* ----------------------------------------------------------------------
 | The permanent URL
 ---------------------------------------------------------------------- */

it('gives every pick a permanent url that shows both timestamps', function () {
    $pick = publishedPick(['selection' => 'Chiefs -3.5']);

    $this->get(route('record.pick', $pick))
        ->assertOk()
        ->assertSee('Chiefs -3.5')
        // Both timestamps, so a reader can check the pick was published
        // before kickoff rather than take it on trust.
        ->assertSee($pick->published_at->toIso8601String())
        ->assertSee($pick->kickoff_at->toIso8601String())
        ->assertSee('cannot be edited');
});

it('keys the permanent url on the public id, not the row id', function () {
    $pick = publishedPick();

    // So the link survives this table being rebuilt from its own
    // payloads.
    expect(route('record.pick', $pick))->toContain($pick->public_id)
        ->and(route('record.pick', $pick))->not->toContain('/record/'.$pick->id);
});

it('says a pick is unsettled rather than implying a result', function () {
    $pick = publishedPick();

    $this->get(route('record.pick', $pick))
        ->assertOk()
        ->assertSee('Not settled yet')
        ->assertSee('nothing here is marked by hand');
});

it('shows the result and the closing line once settled', function () {
    $pick = publishedPick(['price_american' => 150]);
    resultFor($pick, 'won', 1.5, clv: 0.031);

    $this->get(route('record.pick', $pick))
        ->assertOk()
        ->assertSee('Won')
        ->assertSee('+1.50')
        ->assertSee('+3.10%');
});

it('never mentions a backtest or a paper result on the record', function () {
    resultFor(publishedPick(), 'won', 0.91);

    $body = $this->get(route('record'))->assertOk()->getContent();

    // §7: never mix paper results, backtests and published picks in one
    // number. The page says so, and the guarantee is structural - this
    // table only holds picks published before kickoff.
    expect($body)->toContain('No backtests, no paper trading');
});
