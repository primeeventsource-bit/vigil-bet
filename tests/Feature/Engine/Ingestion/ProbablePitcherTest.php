<?php

declare(strict_types=1);

use App\Enums\StarterRole;
use App\Exceptions\AppendOnlyViolation;
use App\Models\Event;
use App\Models\EventExternalId;
use App\Models\ProbableStarter;
use App\Models\Team;
use App\Services\Ingestion\MlbProbablePitcherSource;
use Illuminate\Support\Facades\Http;

/*
 * Payload shape captured live: probablePitcher hangs off each side of
 * teams, carries only id, fullName and link, and handedness is not in it -
 * it is on the player, which is why there is a second request.
 */

function mlbScheduleEntry(int $gamePk, ?array $homePitcher, ?array $awayPitcher): array
{
    return [
        'gamePk' => $gamePk,
        'gameDate' => '2026-07-04T15:05:00Z',
        'teams' => [
            'home' => array_filter([
                'team' => ['id' => 120, 'name' => 'Washington Nationals'],
                'probablePitcher' => $homePitcher,
            ]),
            'away' => array_filter([
                'team' => ['id' => 134, 'name' => 'Pittsburgh Pirates'],
                'probablePitcher' => $awayPitcher,
            ]),
        ],
    ];
}

function fakeMlbSchedule(array $games, array $people = []): void
{
    Http::fake([
        'statsapi.mlb.com/api/v1/schedule*' => Http::response([
            'dates' => [['games' => $games]],
        ]),
        'statsapi.mlb.com/api/v1/people*' => Http::response(['people' => $people]),
    ]);
}

/*
 * Events carry a unique natural key of sport, start time and the two
 * teams, so two games between the same teams need different start times -
 * which is what a real doubleheader has.
 */
function mlbEvent(int $gamePk = 822716, int $hoursOut = 48): Event
{
    $home = Team::firstOrCreate(
        ['sport' => 'mlb', 'slug' => 'washington-nationals'],
        ['name' => 'Washington Nationals', 'display_name' => 'Washington Nationals'],
    );
    $away = Team::firstOrCreate(
        ['sport' => 'mlb', 'slug' => 'pittsburgh-pirates'],
        ['name' => 'Pittsburgh Pirates', 'display_name' => 'Pittsburgh Pirates'],
    );

    $event = Event::create([
        'sport' => 'mlb',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'starts_at' => now()->addHours($hoursOut),
        'status' => 'scheduled',
    ]);

    EventExternalId::create([
        'event_id' => $event->id,
        'source' => 'mlb_statsapi',
        'external_id' => (string) $gamePk,
    ]);

    return $event;
}

it('records a probable pitcher for each side', function () {
    $event = mlbEvent();

    fakeMlbSchedule([mlbScheduleEntry(
        822716,
        ['id' => 687223, 'fullName' => 'Carson Palmquist'],
        ['id' => 677952, 'fullName' => 'Braxton Ashcraft'],
    )]);

    $stats = app(MlbProbablePitcherSource::class)->sync();

    expect($stats['starters'])->toBe(2)
        ->and($stats['games'])->toBe(1)
        ->and(ProbableStarter::count())->toBe(2);

    $home = ProbableStarter::where('team_id', $event->home_team_id)->sole();

    expect($home->player_name)->toBe('Carson Palmquist')
        ->and($home->player_external_id)->toBe('687223')
        ->and($home->role)->toBe(StarterRole::Pitcher);
});

it('fills in handedness from the batched player lookup', function () {
    // Left-handedness against a lineup is most of why knowing the starter
    // matters, and it is not on the schedule payload at all.
    mlbEvent();

    fakeMlbSchedule(
        [mlbScheduleEntry(
            822716,
            ['id' => 687223, 'fullName' => 'Carson Palmquist'],
            ['id' => 677952, 'fullName' => 'Braxton Ashcraft'],
        )],
        [
            ['id' => 687223, 'fullName' => 'Carson Palmquist', 'pitchHand' => ['code' => 'L']],
            ['id' => 677952, 'fullName' => 'Braxton Ashcraft', 'pitchHand' => ['code' => 'R']],
        ],
    );

    app(MlbProbablePitcherSource::class)->sync();

    expect(ProbableStarter::where('player_external_id', '687223')->sole()->handedness)->toBe('L')
        ->and(ProbableStarter::where('player_external_id', '677952')->sole()->handedness)->toBe('R');
});

it('asks for every pitcher on the slate in one request', function () {
    mlbEvent(1, 48);
    mlbEvent(2, 52);

    fakeMlbSchedule([
        mlbScheduleEntry(1, ['id' => 1, 'fullName' => 'A'], ['id' => 2, 'fullName' => 'B']),
        mlbScheduleEntry(2, ['id' => 3, 'fullName' => 'C'], ['id' => 4, 'fullName' => 'D']),
    ]);

    app(MlbProbablePitcherSource::class)->sync();

    // One schedule call and one people call, not one per pitcher.
    Http::assertSentCount(2);
});

it('leaves handedness empty when the lookup does not say', function () {
    mlbEvent();

    fakeMlbSchedule([mlbScheduleEntry(822716, ['id' => 687223, 'fullName' => 'Carson Palmquist'], null)]);

    app(MlbProbablePitcherSource::class)->sync();

    expect(ProbableStarter::sole()->handedness)->toBeNull();
});

it('counts a side with nobody named yet rather than writing a blank', function () {
    // Normal days out, and most of the slate.
    mlbEvent();

    fakeMlbSchedule([mlbScheduleEntry(822716, ['id' => 687223, 'fullName' => 'Carson Palmquist'], null)]);

    $stats = app(MlbProbablePitcherSource::class)->sync();

    expect($stats['starters'])->toBe(1)
        ->and($stats['without_pitcher'])->toBe(1)
        ->and(ProbableStarter::count())->toBe(1);
});

it('never claims a probable pitcher is confirmed', function () {
    // MLB calls these probable and does not say when one becomes official.
    mlbEvent();

    fakeMlbSchedule([mlbScheduleEntry(822716, ['id' => 687223, 'fullName' => 'Carson Palmquist'], null)]);

    app(MlbProbablePitcherSource::class)->sync();

    expect(ProbableStarter::sole()->is_confirmed)->toBeFalse();
});

it('skips a game whose id this app does not hold', function () {
    // A schedule that has not been pulled, which is a different problem
    // from a missing pitcher.
    fakeMlbSchedule([mlbScheduleEntry(999999, ['id' => 1, 'fullName' => 'A'], null)]);

    $stats = app(MlbProbablePitcherSource::class)->sync();

    expect($stats['unmatched_games'])->toBe(1)
        ->and($stats['starters'])->toBe(0)
        ->and(ProbableStarter::count())->toBe(0);
});

it('matches on the game id so a doubleheader stays two games', function () {
    // Same teams, same day. A name-and-date match would collapse them.
    // Same teams, two start times, which is what a real doubleheader is.
    $first = mlbEvent(100, 48);
    $second = mlbEvent(101, 52);

    fakeMlbSchedule([
        mlbScheduleEntry(100, ['id' => 1, 'fullName' => 'Game One Starter'], null),
        mlbScheduleEntry(101, ['id' => 2, 'fullName' => 'Game Two Starter'], null),
    ]);

    app(MlbProbablePitcherSource::class)->sync();

    expect(ProbableStarter::where('event_id', $first->id)->sole()->player_name)
        ->toBe('Game One Starter')
        ->and(ProbableStarter::where('event_id', $second->id)->sole()->player_name)
        ->toBe('Game Two Starter');
});

it('writes a new row each run rather than replacing the last', function () {
    // A pitcher named four days out and still named on the morning of the
    // game are two different facts, and the second is worth much more.
    mlbEvent();

    fakeMlbSchedule([mlbScheduleEntry(822716, ['id' => 687223, 'fullName' => 'Carson Palmquist'], null)]);

    app(MlbProbablePitcherSource::class)->sync();
    app(MlbProbablePitcherSource::class)->sync();

    expect(ProbableStarter::count())->toBe(2);
});

it('never rewrites a starter once recorded', function () {
    mlbEvent();

    fakeMlbSchedule([mlbScheduleEntry(822716, ['id' => 687223, 'fullName' => 'Carson Palmquist'], null)]);

    app(MlbProbablePitcherSource::class)->sync();

    $starter = ProbableStarter::sole();
    $starter->player_name = 'Someone Else';
    $starter->save();
})->throws(AppendOnlyViolation::class);

it('writes nothing and asks for no players on an empty slate', function () {
    fakeMlbSchedule([]);

    $stats = app(MlbProbablePitcherSource::class)->sync();

    expect($stats['starters'])->toBe(0);

    // No point asking for handedness of nobody.
    Http::assertSentCount(1);
});
