<?php

declare(strict_types=1);

use App\Enums\JobStatus;
use App\Enums\Market;
use App\Enums\Side;
use App\Enums\Sport;
use App\Jobs\Odds\PullPropsForSport;
use App\Models\Event;
use App\Models\JobRun;
use App\Models\OddsSnapshot;
use App\Models\Team;
use Illuminate\Support\Facades\Http;

function seedNbaGame(string $oddsApiEventId = 'evt-1', int $hoursAway = 3): Event
{
    $home = Team::create(['sport' => 'nba', 'name' => 'Celtics '.$oddsApiEventId, 'display_name' => 'Boston Celtics', 'slug' => 'celtics-'.$oddsApiEventId]);
    $away = Team::create(['sport' => 'nba', 'name' => 'Heat '.$oddsApiEventId, 'display_name' => 'Miami Heat', 'slug' => 'heat-'.$oddsApiEventId]);

    return Event::create([
        'sport' => 'nba',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'starts_at' => now()->addHours($hoursAway),
        'status' => 'scheduled',
        'odds_api_event_id' => $oddsApiEventId,
    ]);
}

function propsPayload(string $eventId = 'evt-1'): array
{
    return [
        'id' => $eventId,
        'sport_key' => 'basketball_nba',
        'commence_time' => now()->addHours(3)->toIso8601String(),
        'home_team' => 'Boston Celtics',
        'away_team' => 'Miami Heat',
        'bookmakers' => [[
            'key' => 'draftkings',
            'title' => 'DraftKings',
            'markets' => [
                [
                    'key' => 'player_points',
                    'last_update' => now()->toIso8601String(),
                    'outcomes' => [
                        ['name' => 'Over', 'description' => 'Jayson Tatum', 'price' => -115, 'point' => 27.5],
                        ['name' => 'Under', 'description' => 'Jayson Tatum', 'price' => -105, 'point' => 27.5],
                    ],
                ],
                [
                    'key' => 'player_assists',
                    'last_update' => now()->toIso8601String(),
                    'outcomes' => [
                        ['name' => 'Over', 'description' => 'Jayson Tatum', 'price' => 100, 'point' => 4.5],
                    ],
                ],
            ],
        ]],
    ];
}

beforeEach(function () {
    config()->set('edge.odds_api.key', 'test-key');
    config()->set('edge.odds_api.props.enabled', true);
});

it('stores every prop quote with a market key and no core market', function () {
    seedNbaGame();

    Http::fake(['api.the-odds-api.com/*' => Http::response(propsPayload(), 200, [
        'x-requests-remaining' => '24999000',
        'x-requests-used' => '1000',
        'x-requests-last' => '8',
    ])]);

    dispatch_sync(new PullPropsForSport(Sport::Nba, force: true));

    expect(OddsSnapshot::count())->toBe(3);

    $points = OddsSnapshot::query()->props('player_points')->where('side', Side::Over)->first();

    expect($points->market)->toBeNull()
        ->and($points->market_key)->toBe('player_points')
        ->and($points->player_name)->toBe('Jayson Tatum')
        ->and($points->line)->toEqualWithDelta(27.5, 0.001)
        ->and($points->price_american)->toBe(-115)
        ->and($points->provenance)->toBe('live_pull')
        ->and($points->isProp())->toBeTrue()
        ->and($points->marketKey())->toBe('player_points');
});

it('keeps props out of every reader that feeds the model', function () {
    seedNbaGame();

    Http::fake(['api.the-odds-api.com/*' => Http::response(propsPayload())]);

    dispatch_sync(new PullPropsForSport(Sport::Nba, force: true));

    expect(OddsSnapshot::query()->coreMarkets()->count())->toBe(0)
        ->and(OddsSnapshot::query()->props()->count())->toBe(3)
        ->and(OddsSnapshot::query()->forMarket(Market::Moneyline)->count())->toBe(0);
});

it('records what the pull cost in credits, not in calls', function () {
    seedNbaGame();

    Http::fake(['api.the-odds-api.com/*' => Http::response(propsPayload(), 200, [
        'x-requests-remaining' => '24999000',
        'x-requests-used' => '1000',
        'x-requests-last' => '8',
    ])]);

    dispatch_sync(new PullPropsForSport(Sport::Nba, force: true));

    expect(DB::table('api_requests')->value('credits_cost'))->toBe(8);
});

it('asks only for the markets configured for the sport', function () {
    seedNbaGame();

    Http::fake(['api.the-odds-api.com/*' => Http::response(propsPayload())]);

    dispatch_sync(new PullPropsForSport(Sport::Nba, force: true));

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/events/evt-1/odds')
            && $query['markets'] === 'player_points,player_rebounds,player_assists,player_threes';
    });
});

it('treats a game with no props posted as an ordinary empty answer', function () {
    seedNbaGame();

    Http::fake(['api.the-odds-api.com/*' => Http::response(['message' => 'Event not found'], 404)]);

    dispatch_sync(new PullPropsForSport(Sport::Nba, force: true));

    $run = JobRun::where('job_key', 'odds:props:nba')->first();

    expect($run->status)->toBe(JobStatus::Success)
        ->and($run->records_out)->toBe(0)
        ->and(OddsSnapshot::count())->toBe(0);
});

it('ignores the core markets when they come back from the props endpoint', function () {
    seedNbaGame();

    $payload = propsPayload();
    $payload['bookmakers'][0]['markets'][] = [
        'key' => 'h2h',
        'last_update' => now()->toIso8601String(),
        'outcomes' => [
            ['name' => 'Boston Celtics', 'price' => -160],
            ['name' => 'Miami Heat', 'price' => 135],
        ],
    ];

    Http::fake(['api.the-odds-api.com/*' => Http::response($payload)]);

    dispatch_sync(new PullPropsForSport(Sport::Nba, force: true));

    // Three prop quotes and not one moneyline: the board pull owns those,
    // and a second copy written here would be invisible to the scanner.
    expect(OddsSnapshot::count())->toBe(3)
        ->and(OddsSnapshot::query()->coreMarkets()->count())->toBe(0);
});

it('skips a sport with no prop markets configured', function () {
    Http::fake();

    dispatch_sync(new PullPropsForSport(Sport::Nhl, force: true));

    $run = JobRun::where('job_key', 'odds:props:nhl')->first();

    expect($run->status)->toBe(JobStatus::Skipped)
        ->and($run->error_message)->toContain('No prop markets configured');

    Http::assertNothingSent();
});

it('spends nothing on games beyond the props horizon', function () {
    seedNbaGame('far-away', hoursAway: 200);

    Http::fake();

    dispatch_sync(new PullPropsForSport(Sport::Nba, force: true));

    $run = JobRun::where('job_key', 'odds:props:nba')->first();

    expect($run->status)->toBe(JobStatus::Skipped)
        ->and($run->error_message)->toContain('horizon');

    Http::assertNothingSent();
});

it('refuses prices for a game it did not ask about, and says it happened', function () {
    seedNbaGame('evt-1');

    // The URL asked for evt-1 and the body came back about another game.
    // Filing those prices under evt-1 would be worse than having none.
    Http::fake(['api.the-odds-api.com/*' => Http::response(propsPayload('somebody-elses-game'))]);

    dispatch_sync(new PullPropsForSport(Sport::Nba, force: true));

    $run = JobRun::where('job_key', 'odds:props:nba')->first();

    expect(Event::count())->toBe(1)
        ->and(OddsSnapshot::count())->toBe(0)
        ->and($run->context['wrong_event_returned'])->toBe(1);
});

it('stops part way through a slate when the budget reserve is reached, and says so', function () {
    seedNbaGame('evt-1');
    seedNbaGame('evt-2');

    // Remaining sits under the 10% reserve of the 25,000,000 the headers
    // say the plan is, so the second game is never asked for.
    Http::fake(['api.the-odds-api.com/*' => Http::response(propsPayload(), 200, [
        'x-requests-remaining' => '100',
        'x-requests-used' => '24999900',
        'x-requests-last' => '8',
    ])]);

    dispatch_sync(new PullPropsForSport(Sport::Nba, force: true));

    $run = JobRun::where('job_key', 'odds:props:nba')->first();

    // What was written before the reserve became visible is reported as
    // written. A skip would have claimed nothing happened.
    expect($run->status)->toBe(JobStatus::Success)
        ->and($run->records_in)->toBe(1)
        ->and($run->records_out)->toBe(3)
        ->and($run->context['stopped_on_budget'])->toBeTrue();
});
