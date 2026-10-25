<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Player prop projections
|--------------------------------------------------------------------------
|
| A projection is a probability about one line, and the ways it can be
| wrong are quieter than the ways a rating can be wrong. A rating that
| puts the wrong player top is visible immediately. A projection that is
| eight percent light on every line it is shown looks sober one row at a
| time and only reveals itself as a steady loss.
|
| So these tests pin the two decisions that would cause exactly that,
| and the join the whole feature stands on.
|
*/

use App\Models\Event;
use App\Models\Team;
use App\Services\Players\PlayerNameKey;
use App\Services\Players\PropProjector;
use App\Services\Players\RatingSchema;
use Illuminate\Support\Facades\DB;

function propGame(string $sport, int $n): int
{
    $home = Team::firstOrCreate(
        ['sport' => $sport, 'slug' => 'prop-home-'.$sport],
        ['name' => 'Prop Home '.$sport, 'display_name' => 'Prop Home '.$sport],
    );

    $away = Team::firstOrCreate(
        ['sport' => $sport, 'slug' => 'prop-away-'.$sport],
        ['name' => 'Prop Away '.$sport, 'display_name' => 'Prop Away '.$sport],
    );

    return Event::firstOrCreate(
        [
            'sport' => $sport,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            /*
             * Anchored to the start of the day, not to the clock.
             *
             * firstOrCreate keys on starts_at including its time, so
             * with a bare now() a second ticking over between two calls
             * for the same game produced a second event - and the
             * anytime-touchdown test, which asks for one game twice to
             * file a rushing and a receiving line against it, then saw
             * thirteen games instead of twelve. It passed alone and
             * failed after a slower file, which is the worst way for a
             * fixture to be wrong.
             */
            'starts_at' => now()->startOfDay()->subDays(300)->addDays($n),
        ],
        [
            'season' => (int) now()->year,
            'status' => 'final',
            'final_home_score' => 24,
            'final_away_score' => 20,
        ],
    )->id;
}

/**
 * One stat line.
 *
 * `as_of` is derived from the game rather than from the clock, because
 * the projector weights by recency and reads the order off this column.
 * Stamping every line with now() would make a game log with no history
 * in it.
 */
function propStatLine(
    string $sport,
    string $group,
    string $player,
    int $event,
    array $stats,
    ?string $name = null,
    ?string $externalId = null,
    ?string $asOf = null,
): void {
    DB::table('player_game_stats')->insert([
        'sport' => $sport,
        'event_id' => propGame($sport, $event),
        'team_id' => null,
        'player_external_id' => $externalId ?? $player,
        'player_name' => $name ?? $player,
        'played' => true,
        'stat_group' => $group,
        'stats' => json_encode($stats),
        'source' => 'test',
        // Overridable, so a backfill can be simulated: a row written
        // today for a game played two seasons ago.
        'as_of' => $asOf ?? now()->startOfDay()->subDays(300)->addDays($event)->toDateTimeString(),
        'created_at' => now(),
    ]);
}

/**
 * A game log, oldest first.
 *
 * @param  array<int, float|int|string>  $values  one per game
 */
function propLog(
    string $sport,
    string $group,
    string $statKey,
    string $player,
    array $values,
    int $offset = 0,
    ?string $name = null,
    ?string $externalId = null,
): void {
    foreach (array_values($values) as $i => $value) {
        propStatLine($sport, $group, $player, $offset + $i + 1, [$statKey => (string) $value], $name, $externalId);
    }
}

/*
|--------------------------------------------------------------------------
| The level
|--------------------------------------------------------------------------
*/

it('does not shrink the projected level toward the field', function () {
    /*
     * The decision this whole class turns on, and the one bug that would
     * be invisible in production.
     *
     * The ratings pull every measure toward the peer group mean, which is
     * right for a rank. Applied here it is wrong with a direction: a back
     * averaging 100 rushing yards among a field averaging 25 would
     * project 78, so the model would say "under" on nearly every line it
     * saw, and each individual projection would still read as sober and
     * defensible.
     *
     * Eight replacement-level backs and one star, so there is a low pool
     * to be dragged toward if anybody ever adds a shrink here.
     */
    foreach (range(1, 8) as $i) {
        propLog('nfl', 'rushing', 'rushingYards', 'Scrub '.$i,
            array_fill(0, 16, 20 + $i), $i * 100);
    }

    propLog('nfl', 'rushing', 'rushingYards', 'Star Back', array_fill(0, 16, 100), 2000);

    $projection = app(PropProjector::class)->project('nfl', 'player_rush_yds', 'Star Back', 99.5);

    expect($projection)->not->toBeNull()
        ->and($projection->projectedMean)->toBeGreaterThan(97.0)
        ->and($projection->projectedMean)->toBeLessThan(103.0);
});

it('weights a recent change in role above an old one', function () {
    /*
     * A sample has to span seasons to say anything in October, which is
     * what the user asked for - last season informing this one. But a
     * player whose role has grown is not described by the average of the
     * two roles, so the recent games weigh more.
     *
     * Twenty quiet games, then ten big ones. Compared against the flat
     * average of the same log rather than against a number picked by
     * hand, so the test is about the weighting and not about the
     * half-life that happens to be configured.
     */
    $log = array_merge(array_fill(0, 20, 20), array_fill(0, 10, 90));
    $flat = array_sum($log) / count($log);

    propLog('nfl', 'receiving', 'receivingYards', 'Breakout Receiver', $log);

    $projection = app(PropProjector::class)->project('nfl', 'player_reception_yds', 'Breakout Receiver', 50.5);

    expect($projection->projectedMean)->toBeGreaterThan($flat + 5.0)
        // And not simply the last ten games either - the older ones
        // still inform it.
        ->and($projection->projectedMean)->toBeLessThan(90.0)
        // Thirty games weighted toward the recent ones are not thirty
        // games of evidence, and the number says so.
        ->and($projection->effectiveGames)->toBeLessThan(30.0);
});

it('weights by when a game was played, not by when the row was written', function () {
    /*
     * The bug this pins was invisible, produced sober-looking numbers,
     * and was wrong in a direction.
     *
     * `as_of` is an ingestion stamp. A backfill of an old season runs
     * after the current season has been pulled, so those rows carry the
     * LATER stamp - and ordering a game log by it puts a player's oldest
     * games at the newest end and gives them the heaviest recency
     * weight. In production it had the weighting leaning on Ty Jerome's
     * 5.2-point rookie season while the book priced his current 30, and
     * the model called a 19.5 line a 58-point edge on the under.
     *
     * Twenty old quiet games stamped as written TODAY, then ten recent
     * big ones stamped as written a year ago. Ordered by the stamp the
     * log reads backwards; ordered by the fixture it reads correctly.
     */
    foreach (range(1, 20) as $i) {
        propStatLine('nba', 'all', 'Backfilled Guard', $i, ['points' => '8'],
            null, null, now()->toDateTimeString());
    }

    foreach (range(21, 30) as $i) {
        propStatLine('nba', 'all', 'Backfilled Guard', $i, ['points' => '28'],
            null, null, now()->subYear()->toDateTimeString());
    }

    $projection = app(PropProjector::class)->project('nba', 'player_points', 'Backfilled Guard', 20.5);

    // The flat average is 14.7. Weighted toward the games actually
    // played most recently it must land above that, not below.
    expect($projection->projectedMean)->toBeGreaterThan(16.0);
});

/*
|--------------------------------------------------------------------------
| The families
|--------------------------------------------------------------------------
*/

it('prices anytime touchdown off the share of games, not a count distribution', function () {
    /*
     * The largest prop market on the board, and the one where a count
     * distribution misprices in a knowable direction.
     *
     * A player who scored in 7 of 20 games has a mean of 0.35 and a
     * variance near 0.23, so he is underdispersed, so a negative
     * binomial declines and the fit falls to Poisson - which answers
     * 1 - e^-0.35 = 29.5%. Five and a half points light, every time.
     */
    $log = array_merge(array_fill(0, 13, 0), array_fill(0, 7, 1));
    shuffle($log);

    propLog('nfl', 'rushing', 'rushingTouchdowns', 'Touch Scorer', $log);

    $projection = app(PropProjector::class)->project('nfl', 'player_anytime_td', 'Touch Scorer', 0.5);

    expect($projection->family)->toBe('threshold')
        // Near the observed rate rather than near the Poisson answer.
        ->and($projection->probOver)->toBeGreaterThan(0.30)
        ->and($projection->probOver)->toBeLessThan(0.40)
        ->and(abs($projection->probOver - (1 - exp(-0.35))))->toBeGreaterThan(0.02);
});

it('will not assert a certainty from an absence', function () {
    /*
     * The one place a level IS shrunk, and why. A rate is bounded at
     * zero, so a back who has not scored in eleven games has an unshrunk
     * estimate of exactly nil - a claim that he cannot score, which
     * eleven games cannot support.
     */
    propLog('nfl', 'rushing', 'rushingTouchdowns', 'Blanked Back', array_fill(0, 11, 0));

    $projection = app(PropProjector::class)->project('nfl', 'player_anytime_td', 'Blanked Back', 0.5);

    expect($projection->probOver)->toBeGreaterThan(0.0)
        ->and($projection->probOver)->toBeLessThan(0.25);
});

it('leaves a push on a whole-number line and none on a half', function () {
    /*
     * A player with exactly eight rebounds against a line of eight has
     * neither won nor lost the bet. Folding that into either side
     * overstates it, and on a low-count market it is worth several
     * points.
     */
    propLog('nba', 'all', 'rebounds', 'Board Man',
        [6, 8, 11, 7, 9, 12, 5, 8, 10, 7, 13, 6, 9, 8, 11, 4]);

    $projector = app(PropProjector::class);

    $whole = $projector->project('nba', 'player_rebounds', 'Board Man', 8.0);
    $half = $projector->project('nba', 'player_rebounds', 'Board Man', 8.5);

    expect($whole->probPush)->toBeGreaterThan(0.0)
        ->and($half->probPush)->toBe(0.0);
});

it('returns a distribution rather than three numbers that happen to be near one', function (string $sport, string $market, string $group, string $key, array $values, float $line) {
    propLog($sport, $group, $key, 'Test Subject', $values);

    $projection = app(PropProjector::class)->project($sport, $market, 'Test Subject', $line);

    expect($projection)->not->toBeNull()
        ->and($projection->probOver + $projection->probUnder + $projection->probPush)
        ->toEqualWithDelta(1.0, 1e-9)
        ->and($projection->probOver)->toBeGreaterThanOrEqual(0.0)
        ->and($projection->probOver)->toBeLessThanOrEqual(1.0);
})->with([
    'count' => ['nba', 'player_points', 'all', 'points', [18, 24, 31, 12, 27, 22, 19, 33, 25, 16, 29, 21], 23.5],
    'threes' => ['nba', 'player_threes', 'all', 'threePointFieldGoalsMade-threePointFieldGoalsAttempted', ['2-6', '4-9', '1-5', '3-7', '0-4', '5-11', '2-8', '3-6', '1-3', '4-10', '2-7', '3-9'], 2.5],
    'continuous' => ['nfl', 'player_pass_yds', 'passing', 'passingYards', [245, 310, 198, 287, 332, 221, 265, 301, 189, 276, 294, 233], 269.5],
    'threshold' => ['nfl', 'player_anytime_td', 'rushing', 'rushingTouchdowns', [0, 1, 0, 2, 0, 1, 1, 0, 0, 1, 0, 1], 0.5],
]);

it('reads a compound stat as the half that happened', function () {
    /*
     * The feed writes threes as "made-attempted". A shooter at 5 of 12
     * made five, and a cast would take five by accident while taking
     * nothing at all from "5/12".
     */
    propLog('nba', 'all', 'threePointFieldGoalsMade-threePointFieldGoalsAttempted', 'Deep Shooter',
        array_fill(0, 14, '4-10'));

    $projection = app(PropProjector::class)->project('nba', 'player_threes', 'Deep Shooter', 3.5);

    expect($projection->projectedMean)->toEqualWithDelta(4.0, 0.01);
});

/*
|--------------------------------------------------------------------------
| The join
|--------------------------------------------------------------------------
*/

it('joins across the punctuation the two feeds disagree about', function (string $odds, string $box) {
    expect(PlayerNameKey::for($odds))->toBe(PlayerNameKey::for($box));
})->with([
    'initials' => ['C.J. McCollum', 'CJ McCollum'],
    'more initials' => ['A.J. Green', 'AJ Green'],
    'suffix punctuation' => ['Jabari Smith Jr', 'Jabari Smith Jr.'],
    'suffix on one side only' => ['Derrick Jones', 'Derrick Jones Jr.'],
    'roman numerals' => ['Robert Williams', 'Robert Williams III'],
    'hyphen' => ['Amon-Ra St. Brown', 'Amon-Ra St Brown'],
    'accent' => ['Nikola Jokić', 'Nikola Jokic'],
    'case' => ['LAMAR JACKSON', 'Lamar Jackson'],
]);

it('refuses a fantasy team entry rather than hunting for its box score', function (string $name) {
    /*
     * The props feed prices team defences in the same field as players.
     * They are not people, have no player line, and a projection for one
     * would have to be invented.
     */
    expect(PlayerNameKey::isPerson($name))->toBeFalse();
})->with([
    'Arizona Cardinals D/ST',
    'Atlanta Falcons Defense',
    'Ravens',
]);

it('never reduces a name to a bare surname', function () {
    /*
     * Peeling suffixes repeatedly would turn every "Jackson Jr." into
     * "jackson" and match him to every Jackson in the league.
     */
    expect(PlayerNameKey::for('Jones Jr'))->toBe('jones jr')
        ->and(PlayerNameKey::for('Michael Porter Jr.'))->toBe('michael porter')
        // One suffix, not all of them.
        ->and(PlayerNameKey::for('Odell Beckham Jr II'))->toBe('odell beckham jr');
});

it('refuses a name two different players answer to', function () {
    /*
     * Stripping a generational suffix is the one lossy step in the join,
     * and a father and a son land on the same key. Picking either would
     * attach one record to the other's price with nothing downstream
     * able to tell, so the key is dropped.
     */
    propLog('nfl', 'rushing', 'rushingYards', 'senior', array_fill(0, 14, 80),
        0, 'Aaron Jones Sr.', 'espn-1');
    propLog('nfl', 'rushing', 'rushingYards', 'junior', array_fill(0, 14, 30),
        100, 'Aaron Jones Jr.', 'espn-2');

    $projector = app(PropProjector::class);

    expect($projector->project('nfl', 'player_rush_yds', 'Aaron Jones', 49.5))->toBeNull()
        ->and($projector->reasonFor('nfl', 'player_rush_yds', 'Aaron Jones'))->toBe('no box scores');
});

it('counts one game once when a player scored two ways in it', function () {
    /*
     * Anytime touchdown sums six stat groups. A player who ran one in
     * and caught one has two touchdowns in one game, filed by the feed
     * as two rows. Counted as two games he would look like a man who
     * scores every time out.
     */
    foreach (range(1, 12) as $game) {
        propStatLine('nfl', 'rushing', 'Dual Threat', $game, ['rushingTouchdowns' => '1']);
        propStatLine('nfl', 'receiving', 'Dual Threat', $game, ['receivingTouchdowns' => '1']);
    }

    $logs = app(PropProjector::class)->logsFor('nfl', 'player_anytime_td');

    expect($logs['dual threat']['values'])->toHaveCount(12)
        ->and($logs['dual threat']['values'][0])->toEqualWithDelta(2.0, 1e-9);
});

/*
|--------------------------------------------------------------------------
| Refusals
|--------------------------------------------------------------------------
*/

it('refuses a thin sample rather than widening it until it looks adequate', function () {
    /*
     * A refusal is legible. A very wide distribution over four games
     * reads as an opinion, and would be shown beside the ones built on
     * sixty.
     */
    propLog('nba', 'all', 'points', 'Thin Callup', [22, 18, 25, 20]);

    $projector = app(PropProjector::class);

    expect($projector->project('nba', 'player_points', 'Thin Callup', 20.5))->toBeNull()
        ->and($projector->reasonFor('nba', 'player_points', 'Thin Callup'))->toBe('too few games');
});

it('refuses a player whose team has moved on without him', function () {
    /*
     * The loudest error on the live board, and the mirror of the
     * role-growth case. A projection averages a player's history; the
     * market prices the player he is tonight. Adam Trautman averaged
     * 12.8, 14.5 and 17.7 receiving yards over three seasons, last
     * appeared three team games ago, and the board priced over half a
     * yard at even money - the market saying he probably will not catch
     * a pass. The projection called it a 34-point edge.
     *
     * Fourteen games for one team, then three more games that team
     * played without him.
     */
    $team = Team::firstOrCreate(
        ['sport' => 'nfl', 'slug' => 'absent-side'],
        ['name' => 'Absent Side', 'display_name' => 'Absent Side'],
    );

    foreach (range(1, 14) as $game) {
        DB::table('player_game_stats')->insert([
            'sport' => 'nfl',
            'event_id' => propGame('nfl', $game),
            'team_id' => $team->id,
            'player_external_id' => 'benched',
            'player_name' => 'Benched End',
            'played' => true,
            'stat_group' => 'receiving',
            'stats' => json_encode(['receivingYards' => '48']),
            'source' => 'test',
            'as_of' => now()->startOfDay()->subDays(300)->addDays($game)->toDateTimeString(),
            'created_at' => now(),
        ]);
    }

    // His side keeps playing. He does not appear in any of these.
    foreach (range(15, 17) as $game) {
        Event::whereKey(propGame('nfl', $game))->update([
            'home_team_id' => $team->id,
            'status' => 'final',
        ]);
    }

    $projector = app(PropProjector::class);

    expect($projector->project('nfl', 'player_reception_yds', 'Benched End', 0.5))->toBeNull()
        ->and($projector->reasonFor('nfl', 'player_reception_yds', 'Benched End'))
        ->toBe('recently absent');
});

it('still projects a player who has only missed a bye', function () {
    /*
     * Ordinary attrition, not a collapsed role. Refusing on one missed
     * game would drop most of a Sunday.
     */
    $team = Team::firstOrCreate(
        ['sport' => 'nfl', 'slug' => 'bye-side'],
        ['name' => 'Bye Side', 'display_name' => 'Bye Side'],
    );

    foreach (range(40, 53) as $game) {
        DB::table('player_game_stats')->insert([
            'sport' => 'nfl',
            'event_id' => propGame('nfl', $game),
            'team_id' => $team->id,
            'player_external_id' => 'regular',
            'player_name' => 'Regular Starter',
            'played' => true,
            'stat_group' => 'receiving',
            'stats' => json_encode(['receivingYards' => '52']),
            'source' => 'test',
            'as_of' => now()->startOfDay()->subDays(300)->addDays($game)->toDateTimeString(),
            'created_at' => now(),
        ]);
    }

    Event::whereKey(propGame('nfl', 54))->update(['home_team_id' => $team->id, 'status' => 'final']);

    expect(app(PropProjector::class)->project('nfl', 'player_reception_yds', 'Regular Starter', 49.5))
        ->not->toBeNull();
});

it('says which of the reasons it declined for', function () {
    /*
     * A count of nulls says the feature is partly broken without saying
     * which part, and the reasons need different fixes: a missing
     * distribution is a line of config, an unmatched name is a
     * normalising rule, a thin sample is nobody's fault.
     */
    $projector = app(PropProjector::class);

    expect($projector->reasonFor('nba', 'player_blocks', 'Anybody At All'))->toBe('market not modelled')
        ->and($projector->reasonFor('nfl', 'player_anytime_td', 'Chicago Bears D/ST'))->toBe('not a player')
        ->and($projector->reasonFor('nba', 'player_points', 'Nobody Here'))->toBe('no box scores');
});

/*
|--------------------------------------------------------------------------
| Coverage
|--------------------------------------------------------------------------
*/

it('declares a distribution for every prop market it has a stat for', function () {
    /*
     * A market with a stat and no distribution cannot be projected, and
     * finding that out at projection time means finding it out per game
     * forever.
     */
    foreach (array_keys(RatingSchema::PROP_STATS) as $market) {
        expect(RatingSchema::modelForProp($market))
            ->not->toBeNull("no distribution declared for {$market}");
    }

    expect(RatingSchema::projectableProps())
        ->toHaveCount(count(RatingSchema::PROP_STATS));
});

it('can project every market the config actually pulls', function () {
    foreach (['nfl', 'nba'] as $sport) {
        foreach ((array) config("sports.{$sport}.prop_markets", []) as $market) {
            expect(RatingSchema::modelForProp($market))
                ->not->toBeNull("{$market} is pulled but cannot be projected");
        }
    }
});
