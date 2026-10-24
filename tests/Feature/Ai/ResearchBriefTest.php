<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Team;
use App\Services\Ai\ResearchBrief;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The research behind a Read
|--------------------------------------------------------------------------
|
| A Vigil Read used to receive prices, line movement, team names and a
| verdict. On an unproven market - which is every market - that left it
| explaining a number nobody should act on while saying nothing a
| customer could use.
|
| The tests that matter are about restraint rather than richness: that it
| says nothing when it knows nothing, that it names a player once rather
| than once per daily report, and that it always declares the engine has
| not seen any of it.
|
*/

function boxScoredResult(Team $team, Team $opponent, int $for, int $against, int $daysAgo): void
{
    $event = Event::create([
        'sport' => $team->sport,
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'starts_at' => now()->subDays($daysAgo),
        'status' => 'final',
        'final_home_score' => $for,
        'final_away_score' => $against,
    ]);

    DB::table('team_game_stats')->insert([
        'event_id' => $event->id,
        'team_id' => $team->id,
        'opponent_team_id' => $opponent->id,
        'is_home' => true,
        'points' => $for,
        'points_allowed' => $against,
        'stats' => json_encode(['totalYards' => '350']),
        'source' => 'espn',
        'as_of' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('says nothing when it knows nothing', function () {
    // The important default. A brief that invented context would be
    // worse than an empty one, because a Read would then explain it.
    $event = engineGame('Buffalo Bills', 'Miami Dolphins', ['sport' => 'nfl']);

    expect(app(ResearchBrief::class)->forEvent($event->id))->toBe([]);
});

it('returns nothing for an event that does not exist', function () {
    expect(app(ResearchBrief::class)->forEvent(999999))->toBe([]);
});

it('reports form from box scores, with the record', function () {
    $event = engineGame('Buffalo Bills', 'Miami Dolphins', ['sport' => 'nfl']);
    $home = $event->homeTeam;
    $away = $event->awayTeam;

    // Three wins, two losses, 24 for and 17 against on average.
    boxScoredResult($home, $away, 28, 14, 7);
    boxScoredResult($home, $away, 31, 10, 14);
    boxScoredResult($home, $away, 20, 17, 21);
    boxScoredResult($home, $away, 17, 24, 28);
    boxScoredResult($home, $away, 24, 20, 35);

    $brief = app(ResearchBrief::class)->forEvent($event->id);

    expect($brief['home_form_games'])->toBe(5)
        ->and($brief['home_form_record'])->toBe('4-1')
        ->and($brief['home_form_points_for'])->toBe(24.0)
        ->and($brief['home_form_points_against'])->toBe(17.0);
});

it('looks only at games played before this one', function () {
    /*
     * A brief that included a later result would be describing the
     * future, which is the one thing a pre-kickoff figure must never do.
     */
    $event = engineGame('Buffalo Bills', 'Miami Dolphins', ['sport' => 'nfl']);

    boxScoredResult($event->homeTeam, $event->awayTeam, 28, 14, 7);

    // A game after this one's kickoff.
    $later = Event::create([
        'sport' => 'nfl',
        'home_team_id' => $event->home_team_id,
        'away_team_id' => $event->away_team_id,
        'starts_at' => $event->starts_at->addDays(7),
        'status' => 'final',
        'final_home_score' => 50,
        'final_away_score' => 0,
    ]);

    DB::table('team_game_stats')->insert([
        'event_id' => $later->id,
        'team_id' => $event->home_team_id,
        'opponent_team_id' => $event->away_team_id,
        'is_home' => true,
        'points' => 50,
        'points_allowed' => 0,
        'source' => 'espn',
        'as_of' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $brief = app(ResearchBrief::class)->forEvent($event->id);

    expect($brief['home_form_games'])->toBe(1)
        ->and($brief['home_form_points_for'])->toBe(28.0);
});

it('names a player once, not once per daily report', function () {
    /*
     * The feed restates a player's status every day. Without taking the
     * latest per player, a Read would list the same name six times and
     * read as six injuries.
     */
    $event = engineGame('Buffalo Bills', 'Miami Dolphins', ['sport' => 'nfl']);

    foreach ([5, 3, 1] as $daysAgo) {
        DB::table('injury_reports')->insert([
            'sport' => 'nfl',
            'team_id' => $event->home_team_id,
            'player_name' => 'Josh Allen',
            'position' => 'QB',
            'status' => 'out',
            'source' => 'espn',
            'reported_at' => now()->subDays($daysAgo),
            'created_at' => now(),
        ]);
    }

    $brief = app(ResearchBrief::class)->forEvent($event->id);

    expect($brief['home_unavailable_count'])->toBe(1)
        ->and($brief['home_unavailable'])->toBe('Josh Allen (QB, out)');
});

it('names those out and only counts those questionable', function () {
    $event = engineGame('Buffalo Bills', 'Miami Dolphins', ['sport' => 'nfl']);

    foreach ([
        ['Player Out', 'out'],
        ['Player Doubtful', 'doubtful'],
        ['Player Questionable', 'questionable'],
        ['Player Also Questionable', 'questionable'],
        ['Player Active', 'active'],
    ] as [$name, $status]) {
        DB::table('injury_reports')->insert([
            'sport' => 'nfl',
            'team_id' => $event->home_team_id,
            'player_name' => $name,
            'position' => 'WR',
            'status' => $status,
            'source' => 'espn',
            'reported_at' => now()->subHours(2),
            'created_at' => now(),
        ]);
    }

    $brief = app(ResearchBrief::class)->forEvent($event->id);

    // Twenty questionable names would spend a whole Read on players who
    // will probably play, so they are a number rather than a list.
    expect($brief['home_unavailable_count'])->toBe(2)
        ->and($brief['home_unavailable'])->toContain('Player Out')
        ->and($brief['home_unavailable'])->toContain('Player Doubtful')
        ->and($brief['home_unavailable'])->not->toContain('Questionable')
        ->and($brief['home_unavailable'])->not->toContain('Player Active')
        ->and($brief['home_questionable_count'])->toBe(2);
});

it('ignores an injury report older than a week', function () {
    // NCAAF's newest report is from 2022. Stale availability presented
    // as news is worse than none.
    $event = engineGame('Buffalo Bills', 'Miami Dolphins', ['sport' => 'nfl']);

    DB::table('injury_reports')->insert([
        'sport' => 'nfl',
        'team_id' => $event->home_team_id,
        'player_name' => 'Long Retired',
        'position' => 'QB',
        'status' => 'out',
        'source' => 'espn',
        'reported_at' => now()->subMonths(6),
        'created_at' => now(),
    ]);

    expect(app(ResearchBrief::class)->forEvent($event->id))->toBe([]);
});

it('works out rest from the schedule, not from a column', function () {
    /*
     * events.home_rest_days is populated for the NFL and almost nothing
     * else, and the schedule knows for every sport.
     */
    $event = engineGame('Los Angeles Lakers', 'Denver Nuggets', ['sport' => 'nba']);

    Event::create([
        'sport' => 'nba',
        'home_team_id' => $event->home_team_id,
        'away_team_id' => $event->away_team_id,
        'starts_at' => $event->starts_at->subDays(3),
        'status' => 'final',
        'final_home_score' => 110,
        'final_away_score' => 104,
    ]);

    $brief = app(ResearchBrief::class)->forEvent($event->id);

    expect($brief['home_rest_days'])->toBe(3)
        ->and($brief['away_rest_days'])->toBe(3);
});

it('always declares that the engine has not seen any of it', function () {
    /*
     * The clause that keeps this honest. The engine's probability is a
     * power rating; a Read saying "the price reflects the quarterback
     * being out" would describe a mechanism that does not exist, which
     * is the same failure as inventing a number.
     */
    $event = engineGame('Buffalo Bills', 'Miami Dolphins', ['sport' => 'nfl']);

    DB::table('injury_reports')->insert([
        'sport' => 'nfl',
        'team_id' => $event->home_team_id,
        'player_name' => 'Josh Allen',
        'position' => 'QB',
        'status' => 'out',
        'source' => 'espn',
        'reported_at' => now()->subHours(1),
        'created_at' => now(),
    ]);

    $brief = app(ResearchBrief::class)->forEvent($event->id);

    expect($brief)->toHaveKey('research_in_model')
        ->and($brief['research_in_model'])->toBeFalse()
        ->and($brief['research_note'])->toContain('has not seen');
});

it('names a player once when every report shares a timestamp', function () {
    /*
     * The case the earlier test missed. It used distinct days, so
     * "exists a later report" settled it - but the feed writes several
     * rows for one player at an identical reported_at, and then no row
     * is later than any other and every copy survives.
     *
     * Production showed it immediately: "Jordyn Tyson (WR, out), Jordyn
     * Tyson (WR, out), Jordyn Tyson (WR, out)" counted as eight players
     * unavailable.
     */
    $event = engineGame('Buffalo Bills', 'Miami Dolphins', ['sport' => 'nfl']);
    $sameMoment = now()->subHours(2);

    foreach (range(1, 3) as $ignored) {
        DB::table('injury_reports')->insert([
            'sport' => 'nfl',
            'team_id' => $event->home_team_id,
            'player_name' => 'Jordyn Tyson',
            'position' => 'WR',
            'status' => 'out',
            'source' => 'espn',
            'reported_at' => $sameMoment,
            'created_at' => now(),
        ]);
    }

    // And the same for a questionable player, which is counted not named.
    foreach (range(1, 4) as $ignored) {
        DB::table('injury_reports')->insert([
            'sport' => 'nfl',
            'team_id' => $event->home_team_id,
            'player_name' => 'Someone Else',
            'position' => 'TE',
            'status' => 'questionable',
            'source' => 'espn',
            'reported_at' => $sameMoment,
            'created_at' => now(),
        ]);
    }

    $brief = app(ResearchBrief::class)->forEvent($event->id);

    expect($brief['home_unavailable_count'])->toBe(1)
        ->and($brief['home_unavailable'])->toBe('Jordyn Tyson (WR, out)')
        ->and($brief['home_questionable_count'])->toBe(1);
});
