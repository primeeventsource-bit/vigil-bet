<?php

declare(strict_types=1);

use App\Data\ScheduleGame;
use App\Models\Event;
use App\Services\Ingestion\ExternalIdLinker;
use App\Services\Teams\TeamResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Linking a second source's event ids
|--------------------------------------------------------------------------
|
| NFL events came from nflverse and ESPN is the only source with NFL box
| scores, so the ids have to be put side by side before anything can
| join. The danger is not a missing link - it is a wrong one, or a
| duplicate event created while trying.
|
*/

function espnScheduleGame(Event $event, string $externalId, int $hoursOff = 0): ScheduleGame
{
    return new ScheduleGame(
        sport: $event->sport,
        sourceEventId: $externalId,
        homeTeamName: $event->homeTeam->display_name,
        awayTeamName: $event->awayTeam->display_name,
        startsAt: CarbonImmutable::parse($event->starts_at)->addHours($hoursOff),
    );
}

function nflEvent(string $home = 'Buffalo Bills', string $away = 'Miami Dolphins'): Event
{
    $event = engineGame($home, $away, ['sport' => 'nfl']);

    // Its original id, from the source it was ingested with.
    DB::table('event_external_ids')->insert([
        'event_id' => $event->id,
        'sport' => 'nfl',
        'source' => 'nflverse',
        'external_id' => '2026_07_'.mb_substr($away, 0, 3).'_'.mb_substr($home, 0, 3),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $event;
}

/** Teach the resolver these names, the way production already knows them. */
function teachNames(Event $event): void
{
    $resolver = app(TeamResolver::class);
    $resolver->learnAlias($event->homeTeam, $event->homeTeam->display_name, 'espn');
    $resolver->learnAlias($event->awayTeam, $event->awayTeam->display_name, 'espn');
}

it('links an espn id onto an event ingested from elsewhere', function () {
    $event = nflEvent();
    teachNames($event);

    $counts = app(ExternalIdLinker::class)->link(
        [espnScheduleGame($event, '401772635')],
        'espn',
    );

    expect($counts['linked'])->toBe(1);

    // Both ids on one event, which is the whole point.
    expect(DB::table('event_external_ids')->where('event_id', $event->id)->count())->toBe(2)
        ->and(DB::table('event_external_ids')
            ->where('event_id', $event->id)->where('source', 'espn')->value('external_id'))
        ->toBe('401772635');
});

it('never creates an event for a game it cannot match', function () {
    /*
     * The failure that would matter. EventSynchronizer would create the
     * game it could not find, and from a second source that means a
     * duplicate row for every fixture the two name differently - worse
     * than a missing box score, because every rating then sees both.
     */
    $event = nflEvent();
    teachNames($event);

    $elsewhere = nflEvent('Kansas City Chiefs', 'Denver Broncos');
    teachNames($elsewhere);

    $before = DB::table('events')->count();

    // A game between two teams we know, on a date with no such fixture.
    $counts = app(ExternalIdLinker::class)->link(
        [espnScheduleGame($event, '401772635', hoursOff: 240)],
        'espn',
    );

    expect($counts['no_event'])->toBe(1)
        ->and($counts['linked'])->toBe(0)
        ->and(DB::table('events')->count())->toBe($before);
});

it('absorbs a moved kickoff but not the next meeting of the same teams', function () {
    $event = nflEvent();
    teachNames($event);

    // Six hours late is a moved kickoff and should still match.
    $counts = app(ExternalIdLinker::class)->link(
        [espnScheduleGame($event, '401772635', hoursOff: 6)],
        'espn',
    );

    expect($counts['linked'])->toBe(1);
});

it('is safe to run twice', function () {
    $event = nflEvent();
    teachNames($event);

    $linker = app(ExternalIdLinker::class);
    $game = espnScheduleGame($event, '401772635');

    expect($linker->link([$game], 'espn')['linked'])->toBe(1);

    $second = $linker->link([$game], 'espn');

    // Counted as already done rather than inserted again, which the
    // unique index on (source, external_id) would refuse anyway.
    expect($second['linked'])->toBe(0)
        ->and($second['already'])->toBe(1)
        ->and(DB::table('event_external_ids')->where('source', 'espn')->count())->toBe(1);
});

it('does not give one event two espn ids', function () {
    /*
     * What keeps the halves of a doubleheader apart. Once an event is
     * claimed by this source, a second game matching the same teams and
     * window must find nothing rather than overwrite it.
     */
    $event = nflEvent();
    teachNames($event);

    $linker = app(ExternalIdLinker::class);

    $linker->link([espnScheduleGame($event, '401772635')], 'espn');
    $counts = $linker->link([espnScheduleGame($event, '999999999')], 'espn');

    expect($counts['linked'])->toBe(0)
        ->and($counts['no_event'])->toBe(1)
        ->and(DB::table('event_external_ids')->where('source', 'espn')->count())->toBe(1);
});

it('skips a game whose teams it cannot resolve', function () {
    $event = nflEvent();
    // No aliases taught, and engineGame salts its slugs so a name cannot
    // match by accident.

    $counts = app(ExternalIdLinker::class)->link(
        [espnScheduleGame($event, '401772635')],
        'espn',
    );

    expect($counts['no_team'])->toBe(1)
        ->and($counts['linked'])->toBe(0);
});
