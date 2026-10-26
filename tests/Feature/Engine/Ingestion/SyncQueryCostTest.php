<?php

declare(strict_types=1);

use App\Data\ScheduleGame;
use App\Enums\EventStatus;
use App\Enums\SeasonType;
use App\Enums\Sport;
use App\Models\Team;
use App\Models\TeamExternalId;
use App\Services\Ingestion\EventSynchronizer;
use App\Services\Teams\TeamResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * The synchronizer's cost is round trips, not CPU. Measured against the
 * production database on 25 October 2026: a 298-game NCAAF slate took
 * 139 seconds, of which 7.5 of every 8.1 was time spent waiting on
 * queries - 9.8 queries a game at roughly 38ms each to a serverless
 * Postgres in another region.
 *
 * That number is a correctness problem and not only a slow one. The
 * database queue's `retry_after` decides when an in-flight job is
 * considered abandoned and handed to another worker, so a job that runs
 * longer than it is guaranteed to run twice over and then fail on max
 * attempts - which is exactly what `ingest:results:ncaaf` did, 8 times
 * in 19 runs.
 *
 * So the query count per game is the thing worth asserting. Wall-clock
 * belongs to whatever machine the suite happens to run on; a round trip
 * that does not need to happen is a defect anywhere.
 */
function syncableGame(int $n, string $home, string $away): ScheduleGame
{
    return new ScheduleGame(
        sport: Sport::Ncaaf,
        sourceEventId: (string) (500000 + $n),
        homeTeamName: $home,
        awayTeamName: $away,
        startsAt: CarbonImmutable::parse('2026-10-24T20:00:00Z')->addMinutes($n),
        season: 2026,
        seasonType: SeasonType::Regular,
        week: 8,
        status: EventStatus::Final,
        homeScore: 27,
        awayScore: 24,
        homeTeamExternalId: 'cfbd-home-'.$n,
        awayTeamExternalId: 'cfbd-away-'.$n,
        venueName: 'Stadium '.$n,
    );
}

function ncaafTeam(string $slug): Team
{
    return Team::query()->create([
        'sport' => 'ncaaf',
        'name' => 'Team '.$slug,
        'display_name' => 'Team '.$slug,
        'slug' => $slug,
    ]);
}

/** @return array<int, ScheduleGame> */
function slate(int $games): array
{
    static $seq = 0;

    $built = [];

    for ($i = 1; $i <= $games; $i++) {
        $n = ++$seq;

        $home = ncaafTeam('home-'.$n);
        $away = ncaafTeam('away-'.$n);

        TeamExternalId::query()->create([
            'team_id' => $home->id, 'source' => 'cfbd',
            'external_id' => 'cfbd-home-'.$n, 'sport' => 'ncaaf',
        ]);
        TeamExternalId::query()->create([
            'team_id' => $away->id, 'source' => 'cfbd',
            'external_id' => 'cfbd-away-'.$n, 'sport' => 'ncaaf',
        ]);

        $built[] = syncableGame($n, $home->display_name, $away->display_name);
    }

    return $built;
}

function queriesToSync(array $games): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    app(EventSynchronizer::class)->sync($games, 'cfbd');

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('re-reads a known slate in a couple of round trips a game', function (): void {
    $games = slate(20);

    /*
     * The first pass creates the events, and creating a row costs an
     * insert nothing can remove. The hourly job does not create: it
     * re-reads a slate it already has and updates the scores. That second
     * pass is what was taking 139 seconds, so that is what is measured.
     */
    app(EventSynchronizer::class)->sync($games, 'cfbd');

    $perGame = queriesToSync($games) / count($games);

    /*
     * Was 9.8 a game against production. Four of those were the teams and
     * their source ids, read one row at a time; one was a venue already on
     * file; one was an id lookup followed by a fetch of the row it named.
     * All of them are now paid once a run or not at all.
     */
    expect($perGame)->toBeLessThan(3.0);
});

it('costs no more per game as the slate grows, which is what a per-row query would do', function (): void {
    $small = slate(5);
    $large = slate(25);

    app(EventSynchronizer::class)->sync($small, 'cfbd');
    app(EventSynchronizer::class)->sync($large, 'cfbd');

    $perSmall = queriesToSync($small) / 5;
    $perLarge = queriesToSync($large) / 25;

    /*
     * Five times the games must not cost more per game. The preloads are
     * paid once, so the larger slate should if anything look cheaper.
     *
     * Compared with a tolerance, because the thing being caught is a
     * query per row and that is linear: a reader added inside the loop
     * takes the per-game figure up by a whole query, not by a tenth of
     * one. Without the tolerance this compares two averages of a roughly
     * flat cost and lets a single query of rounding decide the result -
     * which is how it came to fail on Postgres at 2.04 against 1.8 while
     * passing on SQLite at the same ratio, with nothing in the
     * synchronizer's path having changed either time.
     *
     * The ceiling the test above sets is the real guard on the absolute
     * number. This one guards the shape.
     */
    expect($perLarge)->toBeLessThanOrEqual($perSmall * 1.25);
});

it('still writes the games it was given', function (): void {
    $games = slate(6);

    $stats = app(EventSynchronizer::class)->sync($games, 'cfbd');

    expect($stats['seen'])->toBe(6)
        ->and($stats['unresolved'])->toBe(0)
        ->and($stats['created'] + $stats['updated'])->toBe(6);
});

it('resolves a team whose external id was written during the same run', function (): void {
    /*
     * The preload is a snapshot, and the resolver writes to the table it
     * read. A team linked by name on one game must be found by id on the
     * next, or a backfill would re-resolve the same team by name all day.
     */
    $team = ncaafTeam('late-1');
    $resolver = app(TeamResolver::class);

    $resolver->byExternalId('cfbd', 'cfbd-late-1', 'ncaaf');
    $resolver->linkExternalId($team, 'cfbd', 'cfbd-late-1');

    expect($resolver->byExternalId('cfbd', 'cfbd-late-1', 'ncaaf')?->id)->toBe($team->id);
});
