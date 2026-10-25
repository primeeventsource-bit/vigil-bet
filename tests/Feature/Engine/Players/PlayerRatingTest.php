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
