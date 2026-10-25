<?php

declare(strict_types=1);

use App\Enums\Sport;
use Illuminate\Console\Scheduling\Schedule;

/*
 * A box score cannot attach to an event until that event has an id the
 * box score source recognises. So any sport whose schedule comes from one
 * place and whose box scores come from ESPN needs the linker run for it,
 * or its stats can never arrive.
 *
 * The schedule used to name `nfl` and say "only the NFL needs this
 * today", which was true when it was written. Then MLB's team_stats
 * source moved to ESPN and nothing updated the schedule: 2,241 of 3,435
 * finished MLB games had no ESPN id, so the nightly box score run had
 * nothing to ask about and the gap did not appear anywhere as an error.
 *
 * This test derives the answer from the config rather than restating it,
 * so moving a source role cannot orphan a sport's box scores again.
 */
function sportsNeedingEspnLinks(): array
{
    $needing = [];

    foreach (Sport::cases() as $sport) {
        $sources = config("sports.{$sport->value}.sources", []);

        if (($sources['team_stats'] ?? null) !== 'espn') {
            continue;
        }

        // A sport whose schedule is ESPN's already carries ESPN's ids.
        if (($sources['schedules'] ?? null) === 'espn') {
            continue;
        }

        $needing[] = $sport->value;
    }

    return $needing;
}

/** @return array<int, string> */
function scheduledLinkCommands(): array
{
    $commands = [];

    foreach (app(Schedule::class)->events() as $event) {
        if (preg_match('/ingest:link-espn\s+(\S+)/', $event->command ?? '', $match) === 1) {
            $commands[] = trim($match[1], "'\"");
        }
    }

    sort($commands);

    return $commands;
}

it('identifies the sports that cannot get box scores without a link', function (): void {
    // Guards the test itself: if this ever comes back empty the
    // assertion below would pass by describing nothing.
    expect(sportsNeedingEspnLinks())->not->toBeEmpty();
});

it('schedules the linker for every sport whose box scores depend on it', function (): void {
    $needed = sportsNeedingEspnLinks();
    sort($needed);

    expect(scheduledLinkCommands())->toBe($needed);
});

it('runs the linker before the box scores, so a game linked this morning gets its stats today', function (): void {
    $events = collect(app(Schedule::class)->events());

    $link = $events->first(fn ($e) => str_contains($e->command ?? '', 'ingest:link-espn'));
    $box = $events->first(fn ($e) => str_contains($e->command ?? '', 'ingest:box-scores'));

    expect($link)->not->toBeNull()
        ->and($box)->not->toBeNull()
        ->and($link->expression)->not->toBe($box->expression);
});
