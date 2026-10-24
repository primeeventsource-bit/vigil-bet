<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\Sport;
use App\Jobs\Odds\BackfillHistoricalOdds;
use App\Models\ApiRequest;
use App\Models\Event;
use App\Models\OddsSnapshot;
use App\Models\Team;
use Illuminate\Support\Facades\Http;

function pastNbaGame(string $slug, Carbon\Carbon|string $startsAt): Event
{
    // Canonical slugs, because the historical writer matches a game
    // through TeamResolver exactly as the live writer does, and the
    // resolver looks a name up by its slug. firstOrCreate so a test can
    // seed two games between the same two teams.
    $home = Team::firstOrCreate(
        ['sport' => 'nba', 'slug' => 'boston-celtics'],
        ['name' => 'Boston Celtics', 'display_name' => 'Boston Celtics', 'abbreviation' => 'BOS'],
    );
    $away = Team::firstOrCreate(
        ['sport' => 'nba', 'slug' => 'miami-heat'],
        ['name' => 'Miami Heat', 'display_name' => 'Miami Heat', 'abbreviation' => 'MIA'],
    );

    return Event::create([
        'sport' => 'nba',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'starts_at' => $startsAt,
        'status' => 'final',
    ]);
}

function historicalPayload(string $snapshotAt, string $commenceAt): array
{
    return [
        'timestamp' => $snapshotAt,
        'previous_timestamp' => $snapshotAt,
        'next_timestamp' => $snapshotAt,
        'data' => [[
            'id' => 'hist-1',
            'sport_key' => 'basketball_nba',
            'commence_time' => $commenceAt,
            'home_team' => 'Boston Celtics',
            'away_team' => 'Miami Heat',
            'bookmakers' => [[
                'key' => 'draftkings',
                'title' => 'DraftKings',
                'markets' => [[
                    'key' => 'h2h',
                    'last_update' => $snapshotAt,
                    'outcomes' => [
                        ['name' => 'Boston Celtics', 'price' => -180],
                        ['name' => 'Miami Heat', 'price' => 155],
                    ],
                ]],
            ]],
        ]],
    ];
}

beforeEach(function () {
    config()->set('edge.odds_api.key', 'test-key');
    config()->set('edge.odds_api.regions', ['us', 'us2']);
});

it('prices a backfill before spending anything on it', function () {
    $kickoff = now()->subDays(30);
    pastNbaGame('a', $kickoff);

    Http::fake();

    $this->artisan('edge:odds:backfill', [
        'sport' => 'nba',
        '--from' => $kickoff->copy()->subDay()->toDateString(),
        '--to' => $kickoff->copy()->addDay()->toDateString(),
        '--estimate' => true,
    ])
        // One request, three markets, two regions, ten credits each.
        ->expectsOutputToContain('1 request(s) at 60 credits each')
        ->expectsOutputToContain('Nothing was queued and nothing was spent')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('refuses a backfill that would cost more than its share of what is left', function () {
    $kickoff = now()->subDays(30);
    pastNbaGame('a', $kickoff);

    // Only 1,000 credits left. The 10% reserve of the 25,000,000 plan is
    // far above that, so nothing at all is spendable.
    ApiRequest::create([
        'provider' => 'the_odds_api',
        'endpoint' => 'sports/basketball_nba/odds',
        'http_status' => 200,
        'quota_used' => 24999000,
        'quota_remaining' => 1000,
        'requested_at' => now()->subHour(),
    ]);

    Http::fake();

    $this->artisan('edge:odds:backfill', [
        'sport' => 'nba',
        '--from' => $kickoff->copy()->subDay()->toDateString(),
        '--to' => $kickoff->copy()->addDay()->toDateString(),
    ])
        ->expectsOutputToContain('Refused.')
        ->assertFailed();

    Http::assertNothingSent();
});

it('refuses when there is no schedule to hang prices on', function () {
    Http::fake();

    $this->artisan('edge:odds:backfill', ['sport' => 'nba', '--estimate' => true])
        ->expectsOutputToContain('Nothing to backfill')
        ->assertFailed();

    Http::assertNothingSent();
});

it('writes past prices stamped with when they were true, not with now', function () {
    $kickoff = now()->subDays(30);
    $snapshotAt = $kickoff->copy()->subMinutes(5);

    pastNbaGame('a', $kickoff);

    Http::fake(['api.the-odds-api.com/*' => Http::response(
        historicalPayload($snapshotAt->toIso8601String(), $kickoff->toIso8601String()),
        200,
        ['x-requests-remaining' => '24000000', 'x-requests-used' => '1000000', 'x-requests-last' => '60'],
    )]);

    $this->artisan('edge:odds:backfill', [
        'sport' => 'nba',
        '--from' => $kickoff->copy()->subDay()->toDateString(),
        '--to' => $kickoff->copy()->addDay()->toDateString(),
        '--sync' => true,
    ])->assertSuccessful();

    expect(OddsSnapshot::count())->toBe(2);

    $quote = OddsSnapshot::query()->forMarket(Market::Moneyline)->where('price_american', -180)->first();

    expect($quote->provenance)->toBe('historical_api')
        ->and($quote->market_key)->toBeNull()
        ->and($quote->pulled_at->diffInMinutes($snapshotAt, true))->toBeLessThan(1);
});

it('asks the historical endpoint for the moment just before kickoff', function () {
    $kickoff = now()->subDays(30)->setTime(19, 0);

    pastNbaGame('a', $kickoff);

    Http::fake(['api.the-odds-api.com/*' => Http::response(
        historicalPayload($kickoff->copy()->subMinutes(5)->toIso8601String(), $kickoff->toIso8601String())
    )]);

    $this->artisan('edge:odds:backfill', [
        'sport' => 'nba',
        '--from' => $kickoff->copy()->subDay()->toDateString(),
        '--to' => $kickoff->copy()->addDay()->toDateString(),
        '--sync' => true,
    ])->assertSuccessful();

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        // 18:45, the kickoff less the five minute lead, floored onto the
        // fifteen minute bucket grid.
        return str_contains($request->url(), '/historical/sports/basketball_nba/odds')
            && str_contains($query['date'], 'T18:45:00Z');
    });
});

it('matches a game that no live pull ever touched, and stamps the id on it', function () {
    $kickoff = now()->subDays(30);
    $event = pastNbaGame('a', $kickoff);

    expect($event->odds_api_event_id)->toBeNull();

    Http::fake(['api.the-odds-api.com/*' => Http::response(
        historicalPayload($kickoff->copy()->subMinutes(5)->toIso8601String(), $kickoff->toIso8601String())
    )]);

    $this->artisan('edge:odds:backfill', [
        'sport' => 'nba',
        '--from' => $kickoff->copy()->subDay()->toDateString(),
        '--to' => $kickoff->copy()->addDay()->toDateString(),
        '--sync' => true,
    ])->assertSuccessful();

    expect($event->fresh()->odds_api_event_id)->toBe('hist-1')
        ->and(OddsSnapshot::count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Standing aside for live work
|--------------------------------------------------------------------------
|
| Queueing 4,913 backfill jobs starved live odds ingestion completely:
| zero successful pulls in thirty minutes, with 218 of them queued the
| whole time behind the backfill on a single FIFO worker.
|
| The cost was not the delay. A closing line only exists in the minutes
| before kickoff, so an hour of starvation loses those games' closing
| lines for good - which is the exact measurement the backfill was queued
| to improve.
|
*/

it('knows when live work is waiting, and when it is not', function () {
    $job = new BackfillHistoricalOdds(
        Sport::Nba,
        now()->subDays(30)->toIso8601String(),
    );

    expect($job->liveWorkIsWaiting())->toBeFalse();

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\Jobs\Odds\PullOddsForSport']),
        'attempts' => 0,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    expect($job->liveWorkIsWaiting())->toBeTrue();
});

it('ignores other backfill jobs, or it would never start', function () {
    // The queue is full of its own siblings by design. Only live work
    // counts, otherwise the first job queued stands aside for the second
    // for ever and the backfill never runs at all.
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\Jobs\Odds\BackfillHistoricalOdds']),
        'attempts' => 0,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    $job = new BackfillHistoricalOdds(
        Sport::Nba,
        now()->subDays(30)->toIso8601String(),
    );

    expect($job->liveWorkIsWaiting())->toBeFalse();
});

it('spends nothing on a provider while live work is waiting', function () {
    $kickoff = now()->subDays(30);
    pastNbaGame('a', $kickoff);

    Http::fake(['api.the-odds-api.com/*' => Http::response(
        historicalPayload($kickoff->copy()->subMinutes(5)->toIso8601String(), $kickoff->toIso8601String()),
        200,
        ['x-requests-remaining' => '24000000', 'x-requests-used' => '1000000', 'x-requests-last' => '60'],
    )]);

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\Jobs\Odds\PullOddsForSport']),
        'attempts' => 0,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    dispatch_sync(new BackfillHistoricalOdds(
        Sport::Nba,
        $kickoff->copy()->subMinutes(5)->toIso8601String(),
    ));

    /*
     * Nothing asked for and nothing written. A job that stood aside but
     * had already spent its ten credits per market per region would be
     * the worst of both outcomes.
     */
    Http::assertNothingSent();
    expect(OddsSnapshot::count())->toBe(0);
});
