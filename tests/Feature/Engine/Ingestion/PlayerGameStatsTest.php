<?php

declare(strict_types=1);

use App\Data\PlayerGameStatLine;
use App\Enums\Sport;
use App\Models\Event;
use App\Services\Ingestion\EspnSource;
use App\Services\Ingestion\PlayerGameStatsWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Player box scores
|--------------------------------------------------------------------------
|
| ESPN returns parallel arrays - `keys` naming the columns, each
| athlete's `stats` holding the values in the same order - so the parsing
| is positional and a shift of one would misfile every number silently.
| That is most of what these cover.
|
| The writer is deliberately more forgiving than the team one. A team box
| score on the wrong team is a lie about who played; a player line whose
| side did not resolve is still a true statement about the game.
|
*/

function playerLine(array $overrides = []): PlayerGameStatLine
{
    $values = array_merge([
        'sport' => Sport::Nba,
        'sourceEventId' => '401909851',
        'playerExternalId' => '3936099',
        'playerName' => 'Derrick Jones Jr.',
        'sourceTeamName' => null,
        'sourceTeamId' => null,
        'position' => 'SF',
        'played' => true,
        'statGroup' => 'all',
        'stats' => ['PTS' => '18', 'REB' => '6', 'AST' => '2'],
        'source' => 'espn',
    ], $overrides);

    return new PlayerGameStatLine(...$values);
}

function nbaGame(): Event
{
    return engineGame('Los Angeles Lakers', 'Denver Nuggets', ['sport' => 'nba']);
}

function linkEspn(Event $event, string $externalId): void
{
    DB::table('event_external_ids')->insert([
        'event_id' => $event->id,
        'sport' => $event->sport->value,
        'source' => 'espn',
        'external_id' => $externalId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('writes a player line against our event', function () {
    $event = nbaGame();
    linkEspn($event, '401909851');

    $counts = app(PlayerGameStatsWriter::class)->write([playerLine()]);

    expect($counts['written'])->toBe(1);

    $row = DB::table('player_game_stats')->sole();

    expect((int) $row->event_id)->toBe($event->id)
        ->and($row->player_name)->toBe('Derrick Jones Jr.')
        ->and(json_decode((string) $row->stats, true)['PTS'])->toBe('18');
});

it('keeps a line whose team did not resolve rather than losing the roster', function () {
    /*
     * The difference from the team writer, and it is deliberate. An
     * unresolved side on a team box score means the row might be a lie
     * about who played, so that writer refuses it. Here the player, the
     * game and the numbers are all known, and dropping the row would
     * lose a whole roster over one unmatched name.
     */
    $event = nbaGame();
    linkEspn($event, '401909851');

    $counts = app(PlayerGameStatsWriter::class)->write([
        playerLine(['sourceTeamName' => 'Not A Real Franchise']),
    ]);

    expect($counts['written'])->toBe(1)
        ->and($counts['no_team'])->toBe(1)
        ->and(DB::table('player_game_stats')->sole()->team_id)->toBeNull();
});

it('skips a line for a game we do not have', function () {
    $counts = app(PlayerGameStatsWriter::class)->write([playerLine()]);

    expect($counts['written'])->toBe(0)
        ->and($counts['no_event'])->toBe(1);
});

it('keeps a dressed inactive, which is not a zero line', function () {
    $event = nbaGame();
    linkEspn($event, '401909851');

    $counts = app(PlayerGameStatsWriter::class)->write([
        playerLine(['played' => false, 'stats' => []]),
    ]);

    // "Did not play" is a fact about the game. An empty stat set only
    // means nothing when the source also claims they played.
    expect($counts['written'])->toBe(1)
        ->and($counts['empty'])->toBe(0)
        ->and((bool) DB::table('player_game_stats')->sole()->played)->toBeFalse();
});

it('files the same player twice when the sport has stat groups', function () {
    /*
     * A quarterback who also ran appears under passing and rushing in
     * one game. Those are two rows, not a conflict, which is why the
     * group is part of the unique key.
     */
    $event = nbaGame();
    linkEspn($event, '401909851');

    $counts = app(PlayerGameStatsWriter::class)->write([
        playerLine(['statGroup' => 'passing', 'stats' => ['YDS' => '301']]),
        playerLine(['statGroup' => 'rushing', 'stats' => ['YDS' => '22']]),
    ]);

    expect($counts['written'])->toBe(2)
        ->and(DB::table('player_game_stats')->count())->toBe(2);
});

it('writes one row when the same line arrives twice in a batch', function () {
    /*
     * An upsert naming the same row twice in one statement is an error
     * in Postgres, not a silent overwrite - "cannot affect row a second
     * time" - so the batch has to be clean before it is sent. SQLite
     * tolerates it, so this test is about the shape, not the engine.
     */
    $event = nbaGame();
    linkEspn($event, '401909851');

    $counts = app(PlayerGameStatsWriter::class)->write([playerLine(), playerLine()]);

    expect($counts['written'])->toBe(1)
        ->and(DB::table('player_game_stats')->count())->toBe(1);
});

it('corrects a player line rather than duplicating it', function () {
    $event = nbaGame();
    linkEspn($event, '401909851');

    app(PlayerGameStatsWriter::class)->write([playerLine(['stats' => ['PTS' => '18']])]);
    app(PlayerGameStatsWriter::class)->write([playerLine(['stats' => ['PTS' => '21']])]);

    expect(DB::table('player_game_stats')->count())->toBe(1)
        ->and(json_decode((string) DB::table('player_game_stats')->sole()->stats, true)['PTS'])->toBe('21');
});

it('reads ESPN parallel arrays by position, not by guesswork', function () {
    /*
     * The parsing that would fail silently. ESPN names the columns once
     * in `keys` and gives each athlete a bare `stats` array in the same
     * order, so an off-by-one would file points as minutes and nothing
     * downstream could tell.
     */
    Http::fake([
        'site.api.espn.com/*/summary*' => Http::response([
            'boxscore' => [
                'teams' => [
                    [
                        'team' => ['id' => '13', 'displayName' => 'Los Angeles Lakers'],
                        'homeAway' => 'home',
                        'statistics' => [
                            ['name' => 'points', 'displayValue' => '118'],
                            ['name' => 'fieldGoalsMade-fieldGoalsAttempted', 'displayValue' => '41-83', 'value' => 41],
                        ],
                    ],
                    [
                        'team' => ['id' => '7', 'displayName' => 'Denver Nuggets'],
                        'homeAway' => 'away',
                        'statistics' => [['name' => 'points', 'displayValue' => '104']],
                    ],
                ],
                'players' => [
                    [
                        'team' => ['id' => '13', 'displayName' => 'Los Angeles Lakers'],
                        'statistics' => [[
                            'name' => 'all',
                            'keys' => ['minutes', 'points', 'rebounds'],
                            'athletes' => [[
                                'active' => true,
                                'athlete' => [
                                    'id' => '3936099',
                                    'displayName' => 'Derrick Jones Jr.',
                                    'position' => ['abbreviation' => 'SF'],
                                ],
                                'stats' => ['31', '18', '6'],
                            ]],
                        ]],
                    ],
                ],
            ],
        ], 200),
    ]);

    $box = app()->makeWith(EspnSource::class, ['sport' => Sport::Nba])
        ->gameBoxScore('401909851');

    expect($box->teams)->toHaveCount(2)
        ->and($box->players)->toHaveCount(1);

    $home = $box->teams[0];

    expect($home->isHome)->toBeTrue()
        ->and($home->points)->toBe(118)
        // Taken from the other side of the same game, not a second call.
        ->and($home->pointsAllowed)->toBe(104)
        /*
         * displayValue, not value. ESPN's `value` for field goals is the
         * made count alone, so reading it would silently throw away the
         * attempts and turn 41-83 into 41.
         */
        ->and($home->stats['fieldGoalsMade-fieldGoalsAttempted'])->toBe('41-83');

    $player = $box->players[0];

    expect($player->playerName)->toBe('Derrick Jones Jr.')
        ->and($player->position)->toBe('SF')
        ->and($player->stats)->toBe(['minutes' => '31', 'points' => '18', 'rebounds' => '6']);
});

it('drops an athlete with no id, which could never be joined', function () {
    Http::fake([
        'site.api.espn.com/*/summary*' => Http::response([
            'boxscore' => [
                'teams' => [],
                'players' => [[
                    'team' => ['id' => '13', 'displayName' => 'Los Angeles Lakers'],
                    'statistics' => [[
                        'name' => 'all',
                        'keys' => ['points'],
                        'athletes' => [
                            ['athlete' => ['displayName' => 'Nameless Someone'], 'stats' => ['4']],
                            ['athlete' => ['id' => '99', 'displayName' => 'Real Player'], 'stats' => ['12']],
                        ],
                    ]],
                ]],
            ],
        ], 200),
    ]);

    $box = app()->makeWith(EspnSource::class, ['sport' => Sport::Nba])
        ->gameBoxScore('401909851');

    // The only reason to keep a player line is to join it to the same
    // player in another game, and an id is what makes that possible.
    expect($box->players)->toHaveCount(1)
        ->and($box->players[0]->playerName)->toBe('Real Player');
});

it('returns an empty box score when ESPN has nothing for the game', function () {
    Http::fake(['site.api.espn.com/*/summary*' => Http::response([], 200)]);

    $box = app()->makeWith(EspnSource::class, ['sport' => Sport::Nba])
        ->gameBoxScore('401909851');

    // Postponed and abandoned fixtures are normal; a backfill walks past
    // them rather than stopping.
    expect($box->isEmpty())->toBeTrue();
});

it('refuses to guess points allowed from one side', function () {
    Http::fake([
        'site.api.espn.com/*/summary*' => Http::response([
            'boxscore' => [
                'teams' => [[
                    'team' => ['id' => '13', 'displayName' => 'Los Angeles Lakers'],
                    'homeAway' => 'home',
                    'statistics' => [['name' => 'points', 'displayValue' => '118']],
                ]],
                'players' => [],
            ],
        ], 200),
    ]);

    $box = app()->makeWith(EspnSource::class, ['sport' => Sport::Nba])
        ->gameBoxScore('401909851');

    expect($box->teams)->toBe([]);
});

it('asks the same question once, however many lines a game has', function () {
    /*
     * The fix this tests is why a backfill of several hundred NFL games
     * managed thirty-two and stopped. A football game yields about
     * eighty player lines, and the writer resolved the event and the
     * team for every one of them - roughly a hundred and sixty queries
     * per game against a serverless database.
     *
     * Asserted as a count rather than a duration: a timing test on a
     * network-backed database measures the network.
     */
    $event = nbaGame();
    linkEspn($event, '401909851');

    $lines = [];

    for ($i = 1; $i <= 40; $i++) {
        $lines[] = playerLine([
            'playerExternalId' => (string) (100000 + $i),
            'playerName' => 'Player '.$i,
            'sourceTeamName' => 'Los Angeles Lakers',
        ]);
    }

    $lookups = 0;

    DB::listen(function ($query) use (&$lookups): void {
        $sql = mb_strtolower($query->sql);

        if (str_contains($sql, 'event_external_ids') || str_contains($sql, 'from "teams"')
            || str_contains($sql, 'team_aliases')) {
            $lookups++;
        }
    });

    $counts = app(PlayerGameStatsWriter::class)->write($lines);

    expect($counts['written'])->toBe(40);

    /*
     * One event and one team between them, so a handful of lookups -
     * the resolver tries an alias table and then the teams table. Forty
     * lines must not mean forty of each, and the bound is deliberately
     * loose: the point is that it does not scale with the line count.
     */
    expect($lookups)->toBeLessThan(10);
});

it('does not re-ask for a game it has already failed to find', function () {
    // A miss is cached as null. Without that, every line of a game we do
    // not have costs a query to learn the same thing again.
    $lines = array_map(
        fn (int $i) => playerLine(['playerExternalId' => (string) (200000 + $i)]),
        range(1, 30),
    );

    $queries = 0;

    DB::listen(function ($query) use (&$queries): void {
        if (str_contains(mb_strtolower($query->sql), 'event_external_ids')) {
            $queries++;
        }
    });

    $counts = app(PlayerGameStatsWriter::class)->write($lines);

    expect($counts['no_event'])->toBe(30)
        ->and($queries)->toBe(1);
});
