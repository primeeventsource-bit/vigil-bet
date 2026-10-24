<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\JobStatus;
use App\Enums\Market;
use App\Enums\Side;
use App\Enums\Sport;
use App\Jobs\Odds\PullOddsForSport;
use App\Models\Event;
use App\Models\JobRun;
use App\Models\OddsSnapshot;
use App\Models\Team;
use Illuminate\Support\Facades\Http;

function seedNflTeams(): array
{
    return [
        Team::create(['sport' => 'nfl', 'name' => 'Buffalo Bills', 'display_name' => 'Buffalo Bills', 'slug' => 'buffalo-bills', 'abbreviation' => 'BUF']),
        Team::create(['sport' => 'nfl', 'name' => 'Miami Dolphins', 'display_name' => 'Miami Dolphins', 'slug' => 'miami-dolphins', 'abbreviation' => 'MIA']),
    ];
}

function oddsApiPayload(string $commenceAt): array
{
    return [[
        'id' => 'abc123',
        'sport_key' => 'americanfootball_nfl',
        'commence_time' => $commenceAt,
        'home_team' => 'Buffalo Bills',
        'away_team' => 'Miami Dolphins',
        'bookmakers' => [
            [
                'key' => 'draftkings',
                'title' => 'DraftKings',
                'markets' => [
                    [
                        'key' => 'h2h',
                        'last_update' => $commenceAt,
                        'outcomes' => [
                            ['name' => 'Buffalo Bills', 'price' => -160],
                            ['name' => 'Miami Dolphins', 'price' => 135],
                        ],
                    ],
                    [
                        'key' => 'spreads',
                        'last_update' => $commenceAt,
                        'outcomes' => [
                            ['name' => 'Buffalo Bills', 'price' => -110, 'point' => -3.5],
                            ['name' => 'Miami Dolphins', 'price' => -110, 'point' => 3.5],
                        ],
                    ],
                    [
                        'key' => 'totals',
                        'last_update' => $commenceAt,
                        'outcomes' => [
                            ['name' => 'Over', 'price' => -105, 'point' => 47.5],
                            ['name' => 'Under', 'price' => -115, 'point' => 47.5],
                        ],
                    ],
                ],
            ],
        ],
    ]];
}

beforeEach(function () {
    config()->set('edge.odds_api.key', 'test-key');
    seedNflTeams();
});

it('stores every quote from a pull as append only snapshots', function () {
    $commenceAt = now()->addHours(3)->toIso8601String();

    Http::fake([
        'api.the-odds-api.com/*' => Http::response(oddsApiPayload($commenceAt), 200, [
            'x-requests-remaining' => '19950',
            'x-requests-used' => '50',
        ]),
    ]);

    dispatch_sync(new PullOddsForSport(Sport::Nfl, force: true));

    expect(OddsSnapshot::count())->toBe(6);

    $moneyline = OddsSnapshot::where('market', Market::Moneyline)->where('side', Side::Home)->first();

    expect($moneyline->price_american)->toBe(-160)
        ->and($moneyline->price_decimal)->toEqualWithDelta(1.625, 0.0001)
        ->and($moneyline->line)->toBeNull();

    $spread = OddsSnapshot::where('market', Market::Spread)->where('side', Side::Home)->first();

    expect($spread->line)->toEqualWithDelta(-3.5, 0.001);
});

it('creates the event from the feed when the schedule has not arrived yet', function () {
    Http::fake(['api.the-odds-api.com/*' => Http::response(oddsApiPayload(now()->addHours(3)->toIso8601String()))]);

    dispatch_sync(new PullOddsForSport(Sport::Nfl, force: true));

    $event = Event::first();

    expect($event->sport)->toBe(Sport::Nfl)
        ->and($event->odds_api_event_id)->toBe('abc123')
        ->and($event->status)->toBe(EventStatus::Scheduled);
});

it('records the run and the quota the provider reported', function () {
    Http::fake([
        'api.the-odds-api.com/*' => Http::response(oddsApiPayload(now()->addHours(3)->toIso8601String()), 200, [
            'x-requests-remaining' => '19950',
            'x-requests-used' => '50',
        ]),
    ]);

    dispatch_sync(new PullOddsForSport(Sport::Nfl, force: true));

    $run = JobRun::where('job_key', 'odds:pull:nfl')->first();

    expect($run->status)->toBe(JobStatus::Success)
        ->and($run->records_out)->toBe(6)
        ->and($run->sport)->toBe(Sport::Nfl);

    expect(DB::table('api_requests')->value('quota_remaining'))->toBe(19950);
});

it('records a skip rather than failing when the key is missing', function () {
    config()->set('edge.odds_api.key', null);

    dispatch_sync(new PullOddsForSport(Sport::Nfl, force: true));

    $run = JobRun::where('job_key', 'odds:pull:nfl')->first();

    expect($run->status)->toBe(JobStatus::Skipped)
        ->and($run->error_message)->toContain('ODDS_API_KEY')
        ->and(OddsSnapshot::count())->toBe(0);
});

it('records a failure when the provider errors', function () {
    Http::fake(['api.the-odds-api.com/*' => Http::response(['message' => 'unauthorized'], 401)]);

    expect(fn () => dispatch_sync(new PullOddsForSport(Sport::Nfl, force: true)))->toThrow(Exception::class);

    $run = JobRun::where('job_key', 'odds:pull:nfl')->first();

    expect($run->status)->toBe(JobStatus::Failed)
        ->and($run->error_message)->not->toBeEmpty();
});
