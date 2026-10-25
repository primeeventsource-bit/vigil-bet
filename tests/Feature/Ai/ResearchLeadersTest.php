<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Team;
use App\Services\Ai\ResearchBrief;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Who actually produces
|--------------------------------------------------------------------------
|
| 543,000 player lines were being written across six sports and read by
| nothing. The modeling layer works on ratings and closing lines; the
| research brief used team box scores for form and injuries. So a table
| filled every night informed no pick and no Read.
|
| Each sport names its own stat keys because the feeds do: NFL lines come
| from ESPN and say `passingYards`, college lines come from CFBD and say
| `YDS` inside a `passing` group. Flattening them into one vocabulary
| would mean choosing a winner and losing whatever the loser knew.
|
*/

/** @param  array<int, array{0: string, 1: string, 2: array<string, string>}>  $lines */
function gameWithPlayerLines(Team $team, Team $opponent, int $daysAgo, array $lines): void
{
    $sport = $team->sport instanceof BackedEnum ? $team->sport->value : (string) $team->sport;

    $event = Event::create([
        'sport' => $sport,
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'starts_at' => now()->subDays($daysAgo),
        'status' => 'final',
        'final_home_score' => 24,
        'final_away_score' => 17,
    ]);

    DB::table('team_game_stats')->insert([
        'event_id' => $event->id,
        'team_id' => $team->id,
        'opponent_team_id' => $opponent->id,
        'is_home' => true,
        'points' => 24,
        'points_allowed' => 17,
        'stats' => json_encode([]),
        'source' => 'test',
        'as_of' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ($lines as [$name, $group, $stats]) {
        DB::table('player_game_stats')->insert([
            'sport' => $sport,
            'event_id' => $event->id,
            'team_id' => $team->id,
            'player_external_id' => md5($name.$group),
            'player_name' => $name,
            'played' => true,
            'stat_group' => $group,
            'stats' => json_encode($stats),
            'source' => 'test',
            'as_of' => now(),
            'created_at' => now(),
        ]);
    }
}

it('names the leading passer, rusher and receiver from college box scores', function () {
    $event = engineGame('Wisconsin Badgers', 'USC Trojans', ['sport' => 'ncaaf']);

    foreach ([7, 14] as $daysAgo) {
        gameWithPlayerLines($event->homeTeam, $event->awayTeam, $daysAgo, [
            ['Billy Edwards', 'passing', ['YDS' => '300', 'TD' => '2']],
            ['Backup Quarterback', 'passing', ['YDS' => '40']],
            ['Davion Gause', 'rushing', ['YDS' => '110']],
            ['Star Receiver', 'receiving', ['YDS' => '95']],
        ]);
    }

    $leaders = collect(app(ResearchBrief::class)->forEvent($event->id)['home_leaders'])->keyBy('role');

    expect($leaders->keys()->sort()->values()->all())->toBe(['pass yds', 'rec yds', 'rush yds'])
        ->and($leaders['pass yds']['player'])->toBe('Billy Edwards')
        ->and($leaders['pass yds']['per_game'])->toBe(300.0)
        ->and($leaders['rush yds']['player'])->toBe('Davion Gause')
        ->and($leaders['rec yds']['player'])->toBe('Star Receiver');
});

it('ranks on the total, so one big game does not outrank a season of carries', function () {
    /*
     * Ranking on the average put a receiver who had taken one carry for
     * 65 yards at the top of the Vikings' rushing, which is the best
     * yards per game in the squad and not a fact about their running
     * game. Houston and the Jets came back the same way. The total is
     * what "leading rusher" means.
     */
    $event = engineGame('Texas Longhorns', 'Ole Miss Rebels', ['sport' => 'ncaaf']);

    gameWithPlayerLines($event->homeTeam, $event->awayTeam, 7, [
        ['One Carry Receiver', 'rushing', ['YDS' => '200']],
        ['Every Game Back', 'rushing', ['YDS' => '150']],
    ]);
    gameWithPlayerLines($event->homeTeam, $event->awayTeam, 14, [
        ['Every Game Back', 'rushing', ['YDS' => '150']],
    ]);

    $rush = collect(app(ResearchBrief::class)->forEvent($event->id)['home_leaders'])
        ->firstWhere('role', 'rush yds');

    expect($rush['player'])->toBe('Every Game Back')
        ->and($rush['games'])->toBe(2);
});

it('reports the average over the games played, with the count beside it', function () {
    /*
     * Three games at 120 and five at 72 are different things, and the
     * total alone hides which is which - so the figure is the average
     * over the games the player actually appeared in, and the count of
     * them goes on the line.
     */
    $event = engineGame('Tennessee Volunteers', 'Alabama Crimson Tide', ['sport' => 'ncaaf']);

    foreach ([7, 14, 21] as $daysAgo) {
        gameWithPlayerLines($event->homeTeam, $event->awayTeam, $daysAgo, [
            ['Three Game Back', 'rushing', ['YDS' => '120']],
        ]);
    }

    $rush = collect(app(ResearchBrief::class)->forEvent($event->id)['home_leaders'])
        ->firstWhere('role', 'rush yds');

    expect($rush['per_game'])->toBe(120.0)
        ->and($rush['games'])->toBe(3);
});

it('uses points for basketball, which is what that sport measures a scorer by', function () {
    $event = engineGame('Chicago Bulls', 'Washington Wizards', ['sport' => 'nba']);

    gameWithPlayerLines($event->homeTeam, $event->awayTeam, 3, [
        ['Big Scorer', 'all', ['points' => '34', 'rebounds' => '8']],
        ['Role Player', 'all', ['points' => '6']],
    ]);

    $leaders = app(ResearchBrief::class)->forEvent($event->id)['home_leaders'];

    expect($leaders)->toHaveCount(1)
        ->and($leaders[0]['role'])->toBe('pts')
        ->and($leaders[0]['player'])->toBe('Big Scorer')
        ->and($leaders[0]['per_game'])->toBe(34.0);
});

it('reads the NFL key, which the college key would miss entirely', function () {
    $event = engineGame('Baltimore Ravens', 'Cincinnati Bengals', ['sport' => 'nfl']);

    gameWithPlayerLines($event->homeTeam, $event->awayTeam, 7, [
        ['Pro Passer', 'passing', ['passingYards' => '275']],
    ]);

    $pass = collect(app(ResearchBrief::class)->forEvent($event->id)['home_leaders'])
        ->firstWhere('role', 'pass yds');

    expect($pass['player'])->toBe('Pro Passer')
        ->and($pass['per_game'])->toBe(275.0);
});

it('skips a stat that is not a number, because a completion line is not yardage', function () {
    $event = engineGame('Tulane Green Wave', 'UTSA Roadrunners', ['sport' => 'ncaaf']);

    gameWithPlayerLines($event->homeTeam, $event->awayTeam, 7, [
        ['Odd Line', 'passing', ['YDS' => '27/44']],
    ]);

    expect(app(ResearchBrief::class)->forEvent($event->id))->not->toHaveKey('home_leaders');
});

it('says nothing about leaders while still reporting form, when there are no player lines', function () {
    $event = engineGame('Calgary Flames', 'Detroit Red Wings', ['sport' => 'nhl']);

    gameWithPlayerLines($event->homeTeam, $event->awayTeam, 3, []);

    $brief = app(ResearchBrief::class)->forEvent($event->id);

    expect($brief)->not->toHaveKey('home_leaders')
        ->and($brief)->toHaveKey('home_form_record');
});

it('keeps the leaders out of the model, like every other figure on the sheet', function () {
    $event = engineGame('Utah Mammoth', 'Tampa Bay Lightning', ['sport' => 'nhl']);

    gameWithPlayerLines($event->homeTeam, $event->awayTeam, 3, [
        ['Top Line', 'forwards', ['goals' => '2', 'assists' => '1']],
    ]);

    $brief = app(ResearchBrief::class)->forEvent($event->id);

    expect($brief['research_in_model'])->toBeFalse()
        ->and($brief['home_leaders'][0]['player'])->toBe('Top Line');
});
