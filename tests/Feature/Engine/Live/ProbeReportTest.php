<?php

declare(strict_types=1);

use App\Models\LiveFeedProbe;
use App\Models\User;
use App\Services\Live\ProbeReport;
use Carbon\CarbonImmutable;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

function stateProbe(
    string $source,
    int $secondsIn,
    string $digest,
    bool $changed = false,
    string $gameKey = 'bruins-at-canadiens',
    ?int $staleness = null,
    bool $ok = true,
): LiveFeedProbe {
    return LiveFeedProbe::create([
        'session' => 'audit',
        'sport' => 'nhl',
        'source' => $source,
        'kind' => 'game_state',
        'external_ref' => $source.'-1',
        'game_key' => $gameKey,
        'status' => 'in_progress',
        'observed_at' => CarbonImmutable::parse('2026-10-21 19:00:00')->addSeconds($secondsIn),
        'source_reported_at' => $staleness === null
            ? null
            : CarbonImmutable::parse('2026-10-21 19:00:00')->addSeconds($secondsIn)->subMilliseconds($staleness),
        'staleness_ms' => $staleness,
        'http_ms' => 120,
        'ok' => $ok,
        'changed' => $changed,
        'digest' => $digest,
        'created_at' => now(),
    ]);
}

function oddsProbe(int $secondsIn, bool $changed = false, ?int $staleness = 8000, string $gameKey = 'bruins-at-canadiens'): LiveFeedProbe
{
    return LiveFeedProbe::create([
        'session' => 'audit',
        'sport' => 'nhl',
        'source' => 'the_odds_api',
        'kind' => 'odds',
        'external_ref' => 'odds-1',
        'game_key' => $gameKey,
        'book_key' => 'draftkings',
        'market' => 'h2h',
        'side' => 'home',
        'price_american' => -180,
        'observed_at' => CarbonImmutable::parse('2026-10-21 19:00:00')->addSeconds($secondsIn),
        'staleness_ms' => $staleness,
        'http_ms' => 300,
        'ok' => true,
        'changed' => $changed,
        'digest' => 'price-'.$secondsIn,
        'created_at' => now(),
    ]);
}

it('says plainly when a session has nothing in it', function () {
    expect(app(ProbeReport::class)->forSession('nothing')['empty'])->toBeTrue();
});

it('counts what each feed did', function () {
    stateProbe('espn', 0, 'a');
    stateProbe('espn', 5, 'a');
    stateProbe('espn', 10, 'b', changed: true);
    stateProbe('nhl_api', 0, 'a');
    stateProbe('nhl_api', 5, 'a', ok: false);

    $sources = collect(app(ProbeReport::class)->forSession('audit')['sources'])->keyBy('source');

    expect($sources['espn']['readings'])->toBe(3)
        ->and($sources['espn']['material_changes'])->toBe(1)
        ->and($sources['nhl_api']['failures'])->toBe(1)
        ->and($sources['espn']['http_ms_median'])->toBe(120);
});

it('measures how far one feed trails another on the same game', function () {
    // The NHL feed sees the goal at t=10, ESPN at t=18. Eight seconds is
    // ESPN's lag relative to the only reference available.
    stateProbe('nhl_api', 0, 'score-3-2');
    stateProbe('espn', 2, 'score-3-2');
    stateProbe('nhl_api', 10, 'score-4-2', changed: true);
    stateProbe('espn', 18, 'score-4-2', changed: true);

    $lag = collect(app(ProbeReport::class)->forSession('audit')['relative_lag']['rows'])->keyBy('source');

    expect($lag['nhl_api']['median_ms'])->toBe(0)
        ->and($lag['espn']['median_ms'])->toBe(2000)
        // The NHL feed was first to both states; ESPN to neither.
        ->and($lag['nhl_api']['first_pct'])->toBe(100.0)
        ->and($lag['espn']['first_pct'])->toBe(0.0);
});

it('ignores a state only one feed ever saw', function () {
    // A state nobody else reported says nothing about which feed is faster.
    stateProbe('nhl_api', 0, 'only-mine');
    stateProbe('espn', 4, 'something-else');

    expect(app(ProbeReport::class)->forSession('audit')['relative_lag']['rows'])->toBe([]);
});

it('refuses to compare a game only one feed covered', function () {
    stateProbe('espn', 0, 'a');
    stateProbe('espn', 5, 'b', changed: true);

    $lag = app(ProbeReport::class)->forSession('audit')['relative_lag'];

    expect($lag['rows'])->toBe([])->and($lag['comparable_games'])->toBe(0);
});

it('reports absolute staleness only where a source stamped its readings', function () {
    stateProbe('espn', 0, 'a');
    stateProbe('mlb_statsapi', 0, 'a', staleness: 4000);
    stateProbe('mlb_statsapi', 5, 'a', staleness: 12000);

    $absolute = collect(app(ProbeReport::class)->forSession('audit')['absolute_staleness'])->keyBy('source');

    expect($absolute)->toHaveCount(1)
        ->and($absolute['mlb_statsapi']['median_ms'])->toBe(4000)
        ->and($absolute['mlb_statsapi']['worst_ms'])->toBe(12000);
});

it('says the price half did not run when no price was read', function () {
    stateProbe('espn', 0, 'a');

    expect(app(ProbeReport::class)->forSession('audit')['odds']['measured'])->toBeFalse();
});

it('measures how old the book said its prices were', function () {
    oddsProbe(0, staleness: 4000);
    oddsProbe(30, staleness: 10000);
    oddsProbe(60, staleness: 22000);

    $odds = app(ProbeReport::class)->forSession('audit')['odds'];

    expect($odds['measured'])->toBeTrue()
        ->and($odds['quotes'])->toBe(3)
        ->and($odds['staleness_median_ms'])->toBe(10000)
        ->and($odds['books'])->toBe(1);
});

it('measures the gap between a score changing and a price moving', function () {
    // This is the number the engine lives or dies on.
    stateProbe('nhl_api', 10, 'score-4-2', changed: true);
    oddsProbe(5);
    oddsProbe(22, changed: true);

    $response = app(ProbeReport::class)->forSession('audit')['price_response'];

    expect($response['measured'])->toBeTrue()
        ->and($response['n'])->toBe(1)
        ->and($response['median_ms'])->toBe(12000);
});

it('will not report a price response without both halves', function () {
    stateProbe('nhl_api', 10, 'score-4-2', changed: true);
    oddsProbe(5);

    $response = app(ProbeReport::class)->forSession('audit')['price_response'];

    expect($response['measured'])->toBeFalse()
        ->and($response['score_changes'])->toBe(1)
        ->and($response['price_changes'])->toBe(0);
});

it('does not pair a price move with a score change in another game', function () {
    stateProbe('nhl_api', 10, 'score-4-2', changed: true, gameKey: 'game-one');
    oddsProbe(22, changed: true, gameKey: 'game-two');

    expect(app(ProbeReport::class)->forSession('audit')['price_response']['measured'])->toBeFalse();
});

it('lists the newest sessions first', function () {
    stateProbe('espn', 0, 'a');

    LiveFeedProbe::create([
        'session' => 'older',
        'sport' => 'nhl',
        'source' => 'espn',
        'kind' => 'game_state',
        'observed_at' => CarbonImmutable::parse('2026-10-20 19:00:00'),
        'ok' => true,
        'created_at' => now(),
    ]);

    expect(app(ProbeReport::class)->sessions())->toBe(['audit', 'older']);
});

it('reports a session end to end from the command', function () {
    stateProbe('nhl_api', 0, 'score-3-2');
    stateProbe('espn', 3, 'score-3-2');
    stateProbe('nhl_api', 10, 'score-4-2', changed: true);
    stateProbe('espn', 18, 'score-4-2', changed: true);
    oddsProbe(25, changed: true);

    $this->artisan('edge:live:report audit')
        ->assertSuccessful()
        ->expectsOutputToContain('Lag between feeds on the same game');
});

it('tells you to run a probe before asking for a report', function () {
    $this->artisan('edge:live:report')->assertFailed();
});
