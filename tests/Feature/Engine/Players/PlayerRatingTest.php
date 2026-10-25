<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Player ratings
|--------------------------------------------------------------------------
|
| The 0-99 scale is borrowed from NBA 2K and Madden because it is legible
| at a glance. The method is not borrowed and cannot be - neither
| publishes theirs, and claiming to reproduce them would be a claim about
| somebody else's work.
|
| A rating here means exactly one thing: a percentile within a peer group,
| computed from box scores this database already holds. 94 means the
| player's production sits in the 94th percentile of others in his
| position. These tests pin that meaning, because a number on this scale
| will be read as authoritative whatever the label beside it says.
|
*/

use App\Models\Event;
use App\Models\Team;
use App\Services\Players\PlayerRatingCalculator;
use App\Services\Players\RatingSchema;
use Illuminate\Support\Facades\DB;

/**
 * A finished game to hang a stat line on.
 *
 * Real rows rather than invented ids, because player_game_stats has a
 * foreign key to events - the constraint that keeps a box score attached
 * to a game somebody actually played.
 *
 * Looked up in the database rather than cached in a static, because the
 * database is rolled back between tests and a static is not: the second
 * test would hand out ids for rows that no longer exist.
 */
function ratedGame(string $sport, int $n): int
{
    $home = Team::firstOrCreate(
        ['sport' => $sport, 'slug' => 'home-'.$sport],
        ['name' => 'Home '.$sport, 'display_name' => 'Home '.$sport],
    );

    $away = Team::firstOrCreate(
        ['sport' => $sport, 'slug' => 'away-'.$sport],
        ['name' => 'Away '.$sport, 'display_name' => 'Away '.$sport],
    );

    return Event::firstOrCreate(
        [
            'sport' => $sport,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            // One day per game, and always in the past: clamping the
            // range collapsed separate games onto one date, which made
            // a twelve-game sample look like a single appearance.
            'starts_at' => now()->subDays(1200)->addDays($n),
        ],
        [
            'season' => (int) now()->year,
            'status' => 'final',
            'final_home_score' => 100,
            'final_away_score' => 98,
        ],
    )->id;
}
function ratedStatLine(string $sport, string $group, string $player, int $event, array $stats, ?int $teamId = null): void
{
    DB::table('player_game_stats')->insert([
        'sport' => $sport,
        'event_id' => ratedGame($sport, $event),
        'team_id' => $teamId,
        'player_external_id' => $player,
        'player_name' => 'Player '.$player,
        'played' => true,
        'stat_group' => $group,
        'stats' => json_encode($stats),
        'source' => 'test',
        'as_of' => now(),
        'created_at' => now(),
    ]);
}

/** A player with the same line in every game. */
function ratedConsistent(string $sport, string $group, string $player, array $stats, int $games = 12, int $offset = 0): void
{
    foreach (range(1, $games) as $i) {
        ratedStatLine($sport, $group, $player, $offset + $i, $stats);
    }
}

it('rates the better player higher', function () {
    ratedConsistent('nba', 'all', 'star', ['points' => '30', 'assists' => '8', 'rebounds' => '10', 'steals' => '2', 'blocks' => '1']);
    ratedConsistent('nba', 'all', 'bench', ['points' => '4', 'assists' => '1', 'rebounds' => '2', 'steals' => '0', 'blocks' => '0'], 12, 100);

    $rated = collect(app(PlayerRatingCalculator::class)->forSport('nba'))->keyBy('player_external_id');

    expect($rated['star']['overall'])->toBeGreaterThan($rated['bench']['overall']);
});

it('keeps every rating on the scale it claims', function () {
    foreach (range(1, 6) as $i) {
        ratedConsistent('nba', 'all', 'p'.$i, [
            'points' => (string) ($i * 6), 'assists' => (string) $i,
            'rebounds' => (string) $i, 'steals' => '1', 'blocks' => '0',
        ], 12, $i * 100);
    }

    foreach (app(PlayerRatingCalculator::class)->forSport('nba') as $rating) {
        expect($rating['overall'])->toBeGreaterThanOrEqual(0)
            ->and($rating['overall'])->toBeLessThanOrEqual(99);

        foreach ($rating['components'] as $value) {
            expect($value)->toBeGreaterThanOrEqual(0)->and($value)->toBeLessThanOrEqual(99);
        }
    }
});

it('will not rate a player on too few games', function () {
    /*
     * A percentile over three games ranks noise. Skipped rather than
     * rated low, because "we have not measured him" and "he is bad" are
     * different facts.
     */
    ratedConsistent('nba', 'all', 'regular', ['points' => '20', 'assists' => '5', 'rebounds' => '5', 'steals' => '1', 'blocks' => '1']);
    ratedConsistent('nba', 'all', 'callup', ['points' => '40', 'assists' => '9', 'rebounds' => '9', 'steals' => '3', 'blocks' => '3'], 3, 500);

    $ids = collect(app(PlayerRatingCalculator::class)->forSport('nba'))->pluck('player_external_id');

    expect($ids)->toContain('regular')
        ->and($ids)->not->toContain('callup');
});

it('ranks within a peer group, so position is not mistaken for ability', function () {
    /*
     * A goaltender's save percentage and a forward's goals are not
     * comparable numbers. Rating them in one pool would produce a
     * leaderboard sorted by position.
     */
    ratedConsistent('nhl', 'forwards', 'winger', ['goals' => '1', 'assists' => '1', 'hits' => '3', 'blockedShots' => '1']);
    ratedConsistent('nhl', 'goalies', 'keeper', ['savePct' => '0.930', 'saves' => '32'], 12, 200);

    $rated = collect(app(PlayerRatingCalculator::class)->forSport('nhl'))->keyBy('player_external_id');

    expect($rated['winger']['peer_group'])->toBe('forwards')
        ->and($rated['keeper']['peer_group'])->toBe('goalies');
});

it('averages a rate instead of summing it', function () {
    /*
     * A save percentage added to another save percentage is a number
     * about nothing. Two keepers at .930 are equal however many games
     * they played.
     */
    ratedConsistent('nhl', 'goalies', 'short', ['savePct' => '0.930', 'saves' => '30'], 10);
    ratedConsistent('nhl', 'goalies', 'long', ['savePct' => '0.930', 'saves' => '30'], 40, 300);

    $rated = collect(app(PlayerRatingCalculator::class)->forSport('nhl'))->keyBy('player_external_id');

    expect($rated['short']['components']['save rate'])
        ->toBe($rated['long']['components']['save rate']);
});

it('divides a counting stat by games played, not by the window', function () {
    /*
     * Otherwise missing time reads as playing badly, and a part-season
     * star is rated below a full-season journeyman.
     */
    ratedConsistent('nba', 'all', 'full', ['points' => '20', 'assists' => '4', 'rebounds' => '4', 'steals' => '1', 'blocks' => '1'], 40);
    ratedConsistent('nba', 'all', 'injured', ['points' => '20', 'assists' => '4', 'rebounds' => '4', 'steals' => '1', 'blocks' => '1'], 12, 400);

    $rated = collect(app(PlayerRatingCalculator::class)->forSport('nba'))->keyBy('player_external_id');

    expect($rated['full']['overall'])->toBe($rated['injured']['overall']);
});

it('carries the sample with the number', function () {
    /*
     * A 94 over eleven games and a 94 over four hundred are different
     * statements, and the rating is not shown anywhere without this.
     */
    ratedConsistent('nba', 'all', 'measured', ['points' => '15', 'assists' => '3', 'rebounds' => '3', 'steals' => '1', 'blocks' => '0'], 14);

    $rating = app(PlayerRatingCalculator::class)->forSport('nba')[0];

    expect($rating['games'])->toBe(14)
        ->and($rating['measured_from'])->not->toBeNull()
        ->and($rating['measured_to'])->not->toBeNull();
});

it('gives identical players identical ratings rather than inventing an order', function () {
    ratedConsistent('nba', 'all', 'twin-a', ['points' => '18', 'assists' => '4', 'rebounds' => '6', 'steals' => '1', 'blocks' => '1']);
    ratedConsistent('nba', 'all', 'twin-b', ['points' => '18', 'assists' => '4', 'rebounds' => '6', 'steals' => '1', 'blocks' => '1'], 12, 600);

    $rated = collect(app(PlayerRatingCalculator::class)->forSport('nba'))->keyBy('player_external_id');

    expect($rated['twin-a']['overall'])->toBe($rated['twin-b']['overall']);
});

it('describes a quarterback by what he did, since the box score carries no position', function () {
    /*
     * NFL lines have position on none of 72,382 rows. The stat group is
     * the peer group, and a player filed under passing is a quarterback
     * by his own record.
     */
    ratedConsistent('nfl', 'passing', 'qb', [
        'passingYards' => '280', 'QBRating' => '98.4', 'passingTouchdowns' => '2',
    ], 14);
    ratedConsistent('nfl', 'rushing', 'back', [
        'rushingYards' => '95', 'yardsPerRushAttempt' => '4.6', 'rushingTouchdowns' => '1',
    ], 14, 700);

    $rated = collect(app(PlayerRatingCalculator::class)->forSport('nfl'))->keyBy('player_external_id');

    expect($rated['qb']['peer_group'])->toBe('passing')
        ->and($rated['back']['peer_group'])->toBe('rushing');
});

it('files a two-way player under the role he played most', function () {
    /*
     * A quarterback who runs appears in passing and rushing. One rating
     * per player is stored, and the group he appeared in most is the one
     * that describes him.
     */
    ratedConsistent('nfl', 'passing', 'dual', ['passingYards' => '240', 'QBRating' => '95', 'passingTouchdowns' => '2'], 16);
    ratedConsistent('nfl', 'rushing', 'dual', ['rushingYards' => '30', 'yardsPerRushAttempt' => '5', 'rushingTouchdowns' => '0'], 11, 800);

    $rated = collect(app(PlayerRatingCalculator::class)->forSport('nfl'))
        ->where('player_external_id', 'dual');

    expect($rated)->toHaveCount(1)
        ->and($rated->first()['peer_group'])->toBe('passing');
});

it('skips a stat that is not a number rather than reading it as one', function () {
    ratedConsistent('nfl', 'passing', 'oddline', [
        'passingYards' => '27/44', 'QBRating' => '95', 'passingTouchdowns' => '1',
    ], 12);

    $rating = collect(app(PlayerRatingCalculator::class)->forSport('nfl'))->firstWhere('player_external_id', 'oddline');

    // Rated on what could be read, and the unreadable part is absent
    // rather than counted as 27.
    expect($rating['components'])->not->toHaveKey('passing volume')
        ->and($rating['components'])->toHaveKey('passer rating');
});

/*
|--------------------------------------------------------------------------
| Everyone who turns up on a fantasy team or a prop board
|--------------------------------------------------------------------------
|
| The first version rated quarterbacks, backs, receivers and the defence.
| That left out 63 kickers, 56 punters, 307 kick returners, 164 punt
| returners and the 489 players who recorded an interception - and missed
| the two biggest things on the prop board: anytime touchdown, priced for
| 467 players, and NBA threes, priced for 157.
|
*/

it('rates a kicker, a punter and a returner', function (string $group, array $stats) {
    ratedConsistent('nfl', $group, 'specialist', $stats, 12);

    $rated = collect(app(PlayerRatingCalculator::class)->forSport('nfl'))
        ->firstWhere('player_external_id', 'specialist');

    expect($rated)->not->toBeNull()
        ->and($rated['peer_group'])->toBe($group)
        ->and($rated['components'])->not->toBeEmpty();
})->with([
    'kicker' => ['kicking', [
        'totalKickingPoints' => '9', 'fieldGoalPct' => '85.7',
        'fieldGoalsMade/fieldGoalAttempts' => '2/3', 'longFieldGoalMade' => '47',
    ]],
    'punter' => ['punting', [
        'grossAvgPuntYards' => '46.2', 'puntsInside20' => '3', 'longPunt' => '58', 'touchbacks' => '0',
    ]],
    'kick returner' => ['kickReturns', [
        'kickReturnYards' => '72', 'yardsPerKickReturn' => '24.0',
        'kickReturnTouchdowns' => '0', 'longKickReturn' => '38',
    ]],
    'punt returner' => ['puntReturns', [
        'puntReturnYards' => '31', 'yardsPerPuntReturn' => '10.3',
        'puntReturnTouchdowns' => '0', 'longPuntReturn' => '19',
    ]],
    'ball hawk' => ['interceptions', [
        'interceptions' => '1', 'interceptionYards' => '22', 'interceptionTouchdowns' => '0',
    ]],
]);

it('reads the made half of a compound rather than letting a cast pick it up', function () {
    /*
     * The feed writes threes as "made-attempted". A shooter at 5 of 12
     * has made five; a cast would take five by accident and never know
     * it had, and on "fieldGoalsMade/fieldGoalAttempts" it would take
     * nothing at all.
     */
    ratedConsistent('nba', 'all', 'shooter', [
        'points' => '24', 'rebounds' => '4', 'assists' => '3',
        'threePointFieldGoalsMade-threePointFieldGoalsAttempted' => '5-12',
        'steals' => '1', 'blocks' => '0', 'minutes' => '34', 'turnovers' => '2',
    ], 12);

    ratedConsistent('nba', 'all', 'nonshooter', [
        'points' => '24', 'rebounds' => '4', 'assists' => '3',
        'threePointFieldGoalsMade-threePointFieldGoalsAttempted' => '0-1',
        'steals' => '1', 'blocks' => '0', 'minutes' => '34', 'turnovers' => '2',
    ], 12, 900);

    $rated = collect(app(PlayerRatingCalculator::class)->forSport('nba'))->keyBy('player_external_id');

    expect($rated['shooter']['components']['three point shooting'])
        ->toBeGreaterThan($rated['nonshooter']['components']['three point shooting']);
});

it('scores a cost the other way round', function () {
    /*
     * Turnovers, fumbles lost and goals conceded are things to have
     * fewer of. Ranking them like points would put the most careless
     * player at the top.
     */
    ratedConsistent('nba', 'all', 'careful', [
        'points' => '20', 'rebounds' => '5', 'assists' => '5',
        'threePointFieldGoalsMade-threePointFieldGoalsAttempted' => '2-5',
        'steals' => '1', 'blocks' => '1', 'minutes' => '32', 'turnovers' => '1',
    ], 12);

    ratedConsistent('nba', 'all', 'careless', [
        'points' => '20', 'rebounds' => '5', 'assists' => '5',
        'threePointFieldGoalsMade-threePointFieldGoalsAttempted' => '2-5',
        'steals' => '1', 'blocks' => '1', 'minutes' => '32', 'turnovers' => '7',
    ], 12, 950);

    $rated = collect(app(PlayerRatingCalculator::class)->forSport('nba'))->keyBy('player_external_id');

    expect($rated['careful']['components']['ball security'])
        ->toBeGreaterThan($rated['careless']['components']['ball security'])
        ->and($rated['careful']['overall'])->toBeGreaterThan($rated['careless']['overall']);
});

it('covers every peer group the box scores actually file', function () {
    /*
     * Measured from the data: ten NFL groups, three in hockey, one each
     * for basketball and baseball. A group the feed writes and the
     * schema ignores is a population of players nobody can rate.
     */
    expect(RatingSchema::groups('nfl'))->toHaveCount(10)
        ->and(RatingSchema::groups('nhl'))->toHaveCount(3)
        ->and(RatingSchema::groups('nba'))->toHaveCount(1)
        /*
         * Two for baseball, though the feed files one. Pitchers and
         * batters share a group called `all` and the same words mean
         * opposite things in each, so the role is read off the line.
         */
        ->and(RatingSchema::groups('mlb'))->toBe(['batting', 'pitching']);
});

it('has a stat behind every prop market it prices', function (string $sport, string $market) {
    /*
     * A market with nothing behind it cannot be projected, and finding
     * that out at projection time means finding it out per game forever.
     */
    $stats = RatingSchema::statsForProp($market);

    expect($stats)->not->toBeEmpty();

    foreach ($stats as $stat) {
        expect(RatingSchema::groups($sport))->toContain($stat['group']);
    }
})->with([
    ['nba', 'player_points'],
    ['nba', 'player_rebounds'],
    ['nba', 'player_assists'],
    ['nba', 'player_threes'],
    ['nfl', 'player_pass_yds'],
    ['nfl', 'player_rush_yds'],
    ['nfl', 'player_reception_yds'],
    ['nfl', 'player_anytime_td'],
]);

it('knows a touchdown can be scored from six places', function () {
    /*
     * The largest prop market on the board. A mapping that looked only
     * at rushing would be silent about receivers, returners and the
     * defence.
     */
    $groups = array_column(RatingSchema::statsForProp('player_anytime_td'), 'group');

    expect($groups)->toContain('rushing', 'receiving', 'kickReturns', 'puntReturns', 'interceptions', 'defensive');
});

it('prices every prop market the config asks for', function () {
    /*
     * The config decides which props are pulled; this decides which can
     * be analysed. The second must not be a smaller list than the first.
     */
    foreach (['nfl', 'nba'] as $sport) {
        foreach ((array) config("sports.{$sport}.prop_markets", []) as $market) {
            expect(RatingSchema::statsForProp($market))
                ->not->toBeEmpty("no stat mapped for {$market}");
        }
    }
});

it('does not rate a pitcher as a hitter', function () {
    /*
     * The bug this split exists for. Baseball files both under `all`,
     * and a pitcher's `hits` are hits he allowed - so the first version
     * of this put Aaron Nola top of the league at 98 for "hits 98, power
     * 98" after being hit for five and taken deep twice.
     */
    ratedConsistent('mlb', 'all', 'pitcher', [
        'ERA' => '5.40', 'hits' => '8', 'homeRuns' => '3', 'earnedRuns' => '5',
        'walks' => '3', 'strikeouts' => '7',
    ], 12);

    ratedConsistent('mlb', 'all', 'slugger', [
        'avg' => '.310', 'atBats' => '4', 'hits' => '2', 'homeRuns' => '1',
        'RBIs' => '3', 'runs' => '2', 'slugAvg' => '.620', 'onBasePct' => '.390',
        'strikeouts' => '1',
    ], 12, 1100);

    $rated = collect(app(PlayerRatingCalculator::class)->forSport('mlb'))->keyBy('player_external_id');

    expect($rated['pitcher']['peer_group'])->toBe('pitching')
        ->and($rated['slugger']['peer_group'])->toBe('batting')
        // Hits allowed are a cost, and the label says which they are.
        ->and($rated['pitcher']['components'])->toHaveKey('hits allowed')
        ->and($rated['slugger']['components'])->toHaveKey('hits');
});

it('rewards the pitcher who gives up less', function () {
    ratedConsistent('mlb', 'all', 'ace', [
        'ERA' => '2.10', 'hits' => '3', 'homeRuns' => '0', 'earnedRuns' => '1',
        'walks' => '1', 'strikeouts' => '9',
    ], 12);

    ratedConsistent('mlb', 'all', 'batted-around', [
        'ERA' => '7.80', 'hits' => '11', 'homeRuns' => '3', 'earnedRuns' => '7',
        'walks' => '5', 'strikeouts' => '2',
    ], 12, 1200);

    $rated = collect(app(PlayerRatingCalculator::class)->forSport('mlb'))->keyBy('player_external_id');

    expect($rated['ace']['overall'])->toBeGreaterThan($rated['batted-around']['overall']);
});

it('treats a batter striking out as the cost it is', function () {
    /*
     * The first version weighted batter strikeouts as higher-is-better,
     * which rewarded the worst contact hitter in the sample.
     */
    ratedConsistent('mlb', 'all', 'contact', [
        'avg' => '.300', 'atBats' => '4', 'hits' => '2', 'homeRuns' => '1',
        'RBIs' => '2', 'runs' => '1', 'slugAvg' => '.550', 'onBasePct' => '.360',
        'strikeouts' => '0',
    ], 12);

    ratedConsistent('mlb', 'all', 'whiffer', [
        'avg' => '.300', 'atBats' => '4', 'hits' => '2', 'homeRuns' => '1',
        'RBIs' => '2', 'runs' => '1', 'slugAvg' => '.550', 'onBasePct' => '.360',
        'strikeouts' => '4',
    ], 12, 1300);

    $rated = collect(app(PlayerRatingCalculator::class)->forSport('mlb'))->keyBy('player_external_id');

    expect($rated['contact']['overall'])->toBeGreaterThan($rated['whiffer']['overall']);
});

it('rates a line that is neither in neither', function () {
    /*
     * A pinch runner's line carries no at-bats and no ERA. It says
     * nothing about hitting or pitching, and forcing it into one would
     * invent a player.
     */
    ratedConsistent('mlb', 'all', 'runner', ['runs' => '1'], 12);

    expect(collect(app(PlayerRatingCalculator::class)->forSport('mlb'))
        ->firstWhere('player_external_id', 'runner'))->toBeNull();
});

it('reads baseball innings as thirds, not as a decimal', function () {
    /*
     * "5.2" is five innings and two thirds. Read as five point two it
     * still sorts correctly, which is how a wrong number survives - the
     * ranking looks right while the arithmetic is not, and anything that
     * later sums it inherits the error.
     *
     * Two pitchers, one at 5.2 (5.667) and one at 5.3 (which is not
     * valid notation and is taken at face value).
     */
    $read = new ReflectionMethod(PlayerRatingCalculator::class, 'innings');
    $calculator = app(PlayerRatingCalculator::class);

    expect(round($read->invoke($calculator, '5.2'), 3))->toBe(5.667)
        ->and(round($read->invoke($calculator, '5.1'), 3))->toBe(5.333)
        ->and($read->invoke($calculator, '6.0'))->toBe(6.0)
        // Not valid innings notation, so taken as written rather than
        // silently turned into something else.
        ->and($read->invoke($calculator, '5.7'))->toBe(5.7)
        ->and($read->invoke($calculator, 'not a number'))->toBeNull();
});

it('does not let ten good appearances outrank a season of work', function () {
    /*
     * The leaderboard put Miguel Ullola top at ten appearances and 15
     * innings, ahead of Mason Miller at 130 and 136. A pitching
     * appearance is anything from a third of an inning to seven, so
     * games played says very little about how much a pitcher did.
     */
    ratedConsistent('mlb', 'all', 'cup-of-coffee', [
        'ERA' => '1.80', 'hits' => '1', 'homeRuns' => '0', 'earnedRuns' => '0',
        'walks' => '0', 'strikeouts' => '2', 'fullInnings.partInnings' => '1.0',
    ], 11);

    ratedConsistent('mlb', 'all', 'workhorse', [
        'ERA' => '2.40', 'hits' => '5', 'homeRuns' => '0', 'earnedRuns' => '2',
        'walks' => '1', 'strikeouts' => '7', 'fullInnings.partInnings' => '6.0',
    ], 30, 1400);

    $rated = collect(app(PlayerRatingCalculator::class)->forSport('mlb'))->keyBy('player_external_id');

    expect($rated['workhorse']['components']['innings'])
        ->toBeGreaterThan($rated['cup-of-coffee']['components']['innings']);
});

/*
|--------------------------------------------------------------------------
| A good fortnight is not a good season
|--------------------------------------------------------------------------
|
| A percentile over fifteen innings and one over a hundred and thirty read
| as the same claim unless something asks how long the sample was. They
| are not the same claim, and measured here the difference put a reliever
| with ten appearances above Mason Miller at a hundred and thirty.
|
| So every measure is pulled toward its peer group's average in proportion
| to how little we have seen of the player.
|
*/

it('ranks a long good record above a short brilliant one', function () {
    config()->set('edge.players.shrink_games', 20);

    // Enough players for a group average to exist to shrink toward.
    foreach (range(1, 8) as $i) {
        ratedConsistent('nba', 'all', 'filler'.$i, [
            'points' => '12', 'rebounds' => '4', 'assists' => '3',
            'threePointFieldGoalsMade-threePointFieldGoalsAttempted' => '1-4',
            'steals' => '1', 'blocks' => '0', 'minutes' => '24', 'turnovers' => '2',
        ], 60, 2000 + ($i * 100));
    }

    ratedConsistent('nba', 'all', 'hot-streak', [
        'points' => '34', 'rebounds' => '9', 'assists' => '8',
        'threePointFieldGoalsMade-threePointFieldGoalsAttempted' => '5-9',
        'steals' => '2', 'blocks' => '1', 'minutes' => '36', 'turnovers' => '1',
    ], 11, 3000);

    ratedConsistent('nba', 'all', 'season-long', [
        'points' => '28', 'rebounds' => '8', 'assists' => '7',
        'threePointFieldGoalsMade-threePointFieldGoalsAttempted' => '4-9',
        'steals' => '2', 'blocks' => '1', 'minutes' => '36', 'turnovers' => '1',
    ], 200, 3200);

    $rated = collect(app(PlayerRatingCalculator::class)->forSport('nba'))->keyBy('player_external_id');

    /*
     * The streak player has the better raw numbers in every category and
     * eleven games to show for them. The season-long player is behind on
     * the raw rate and has two hundred games, and should rate higher.
     */
    expect($rated['season-long']['overall'])
        ->toBeGreaterThan($rated['hot-streak']['overall']);
});

it('leaves a long record almost untouched by the shrink', function () {
    /*
     * Regression to the mean applied honestly, not a cap on outliers. A
     * genuinely great player with a long record keeps his number because
     * the sample carries it.
     */
    foreach (range(1, 8) as $i) {
        ratedConsistent('nba', 'all', 'mid'.$i, [
            'points' => '12', 'rebounds' => '4', 'assists' => '3',
            'threePointFieldGoalsMade-threePointFieldGoalsAttempted' => '1-4',
            'steals' => '1', 'blocks' => '0', 'minutes' => '24', 'turnovers' => '2',
        ], 100, 4000 + ($i * 100));
    }

    ratedConsistent('nba', 'all', 'great', [
        'points' => '31', 'rebounds' => '9', 'assists' => '9',
        'threePointFieldGoalsMade-threePointFieldGoalsAttempted' => '4-9',
        'steals' => '2', 'blocks' => '1', 'minutes' => '37', 'turnovers' => '2',
    ], 300, 5000);

    $rated = collect(app(PlayerRatingCalculator::class)->forSport('nba'))->keyBy('player_external_id');

    expect($rated['great']['overall'])->toBeGreaterThan(90);
});

it('can be turned off in configuration rather than in code', function () {
    config()->set('edge.players.shrink_games', 0);

    ratedConsistent('nba', 'all', 'a', [
        'points' => '30', 'rebounds' => '5', 'assists' => '5',
        'threePointFieldGoalsMade-threePointFieldGoalsAttempted' => '3-7',
        'steals' => '1', 'blocks' => '1', 'minutes' => '34', 'turnovers' => '2',
    ], 11, 6000);

    ratedConsistent('nba', 'all', 'b', [
        'points' => '10', 'rebounds' => '2', 'assists' => '2',
        'threePointFieldGoalsMade-threePointFieldGoalsAttempted' => '1-5',
        'steals' => '0', 'blocks' => '0', 'minutes' => '18', 'turnovers' => '3',
    ], 11, 6200);

    $rated = collect(app(PlayerRatingCalculator::class)->forSport('nba'))->keyBy('player_external_id');

    // Equal samples, so with the shrink off the raw rates decide.
    expect($rated['a']['overall'])->toBeGreaterThan($rated['b']['overall']);
});

it('ships with a shrink that actually binds', function () {
    expect((float) config('edge.players.shrink_games'))->toBeGreaterThan(0.0);
});
