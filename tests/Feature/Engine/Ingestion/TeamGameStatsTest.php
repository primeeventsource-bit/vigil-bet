<?php

declare(strict_types=1);

use App\Data\TeamGameStatLine;
use App\Enums\Sport;
use App\Models\Event;
use App\Models\Team;
use App\Services\Ingestion\TeamGameStatsWriter;
use App\Services\Teams\TeamResolver;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Box scores
|--------------------------------------------------------------------------
|
| team_game_stats had a table, a model and an Event relation since the
| schema was written, and never a single row, so every rating in the app
| is built on results alone.
|
| The tests that matter here are about what the writer refuses. A box
| score landing on the wrong team does not look like a gap - it looks
| like a team that played a game it did not play, and every rating
| downstream believes it.
|
*/

/**
 * A line as the source hands it over, before anything is resolved.
 *
 * Defaults are merged rather than read with `??`, because half of these
 * fields are legitimately null and `??` treats an explicit null as
 * absent - which silently turned the "no score, no categories" case into
 * a 24-point game and made that test pass against the wrong thing.
 */
function statLine(array $overrides = []): TeamGameStatLine
{
    $values = array_merge([
        'sport' => Sport::Ncaaf,
        'sourceEventId' => '401767704',
        'sourceTeamName' => 'Michigan',
        'sourceTeamId' => '130',
        'isHome' => true,
        'points' => 24,
        'pointsAllowed' => 17,
        'stats' => ['rushingYards' => '212', 'turnovers' => '1', 'thirdDownEff' => '7-14'],
        'source' => 'cfbd',
    ], $overrides);

    return new TeamGameStatLine(...$values);
}

/**
 * An NCAAF game.
 *
 * engineGame() defaults to NFL and the resolver scopes every lookup by
 * sport, so an NCAAF box score can never resolve against an NFL team -
 * which is correct, and was quietly failing every write here.
 */
function ncaafGame(string $home = 'Michigan Wolverines', string $away = 'Indiana Hoosiers'): Event
{
    return engineGame($home, $away, ['sport' => 'ncaaf']);
}
/**
 * Ties one of our teams to the source's team id.
 *
 * The path production actually resolves on: CFBD sends a teamId with
 * every box score and 714 of them are linked. Matching on the name is
 * the fallback, and the fixture salts its slugs precisely so that a test
 * cannot pass by a name coincidence.
 */
function linkCfbdTeam(Team $team, string $externalId): void
{
    app(TeamResolver::class)->linkExternalId($team, 'cfbd', $externalId);
}

/** The row that ties one of our events to the source's game id. */
function linkCfbd(Event $event, string $externalId): void
{
    DB::table('event_external_ids')->insert([
        'event_id' => $event->id,
        'sport' => $event->sport->value,
        'source' => 'cfbd',
        'external_id' => $externalId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('writes a box score against our own event and teams', function () {
    $event = ncaafGame();

    linkCfbd($event, '401767704');

    $home = $event->homeTeam;
    linkCfbdTeam($home, '130');

    $counts = app(TeamGameStatsWriter::class)->write([statLine()]);

    expect($counts['written'])->toBe(1);

    $row = DB::table('team_game_stats')->sole();

    expect((int) $row->event_id)->toBe($event->id)
        ->and((int) $row->team_id)->toBe($home->id)
        // The other side comes from our event, not from the feed, so a
        // row only exists where both agree on who played.
        ->and((int) $row->opponent_team_id)->toBe($event->away_team_id)
        ->and((int) $row->points)->toBe(24)
        ->and((int) $row->points_allowed)->toBe(17);

    $stats = json_decode((string) $row->stats, true);

    // Categories pass through under their own names, and "7-14" stays
    // "7-14" rather than being cast to 7.
    expect($stats['rushingYards'])->toBe('212')
        ->and($stats['thirdDownEff'])->toBe('7-14');
});

it('skips a game we do not have rather than inventing one', function () {
    // No event_external_ids row at all: the source knows about a game
    // this database has never seen.
    $counts = app(TeamGameStatsWriter::class)->write([statLine()]);

    expect($counts['written'])->toBe(0)
        ->and($counts['no_event'])->toBe(1)
        ->and(DB::table('team_game_stats')->count())->toBe(0);
});

it('refuses a team it cannot resolve rather than guessing', function () {
    $event = ncaafGame();

    linkCfbd($event, '401767704');

    $counts = app(TeamGameStatsWriter::class)->write([
        statLine(['sourceTeamName' => 'Not A Real School', 'sourceTeamId' => null]),
    ]);

    expect($counts['written'])->toBe(0)
        ->and($counts['no_team'])->toBe(1)
        ->and(DB::table('team_game_stats')->count())->toBe(0);
});

it('refuses a resolved team that did not play in that event', function () {
    /*
     * The failure worth catching. The event is ours and the team is
     * ours, but the team is not in that game - an alias pointing at the
     * wrong school, most likely. Writing it anyway would record a result
     * for a team that did not play, and nothing downstream could tell.
     */
    $event = ncaafGame();
    $stranger = ncaafGame('Ohio State Buckeyes', 'Penn State Nittany Lions');

    linkCfbd($event, '401767704');

    linkCfbdTeam($stranger->homeTeam, '130');

    $counts = app(TeamGameStatsWriter::class)->write([statLine()]);

    expect($counts['written'])->toBe(0)
        ->and($counts['no_team'])->toBe(1)
        ->and(DB::table('team_game_stats')->count())->toBe(0);
});

it('corrects a box score rather than duplicating it', function () {
    /*
     * Upsert, not insert. A stat line pulled minutes after the whistle
     * and the same line next morning are not always equal, and the later
     * one is right - which is why this table is not append-only.
     */
    $event = ncaafGame();

    linkCfbd($event, '401767704');

    linkCfbdTeam($event->homeTeam, '130');

    app(TeamGameStatsWriter::class)->write([statLine(['points' => 24])]);

    app(TeamGameStatsWriter::class)->write([
        statLine(['points' => 27, 'stats' => ['rushingYards' => '240']]),
    ]);

    $row = DB::table('team_game_stats')->sole();

    expect((int) $row->points)->toBe(27)
        ->and(json_decode((string) $row->stats, true)['rushingYards'])->toBe('240');
});

it('ignores a row with no score and no categories', function () {
    // The source returned it because the game exists, not because it has
    // been played.
    $counts = app(TeamGameStatsWriter::class)->write([
        statLine(['points' => null, 'pointsAllowed' => null, 'stats' => []]),
    ]);

    expect($counts['empty'])->toBe(1)
        ->and($counts['no_event'])->toBe(0)
        ->and(DB::table('team_game_stats')->count())->toBe(0);
});
