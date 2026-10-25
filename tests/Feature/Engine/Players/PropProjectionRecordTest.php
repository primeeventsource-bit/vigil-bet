<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Recording and grading prop projections
|--------------------------------------------------------------------------
|
| The gate player props sit behind is circular without this. A market
| earns the right to claim an edge by beating a closing line, and nothing
| can beat a closing line it was never measured against - so the
| measuring has to begin while the model is still unpublishable, and the
| record of it has to be one nobody can tidy up afterwards.
|
| Which makes three properties load-bearing, and all three fail quietly
| if they fail at all: the record cannot be edited, it cannot be written
| once the result is known, and one opinion cannot become thirty.
|
*/

use App\Exceptions\AppendOnlyViolation;
use App\Models\Book;
use App\Models\Event;
use App\Models\PropProjection;
use App\Models\PropProjectionResult;
use App\Models\Team;
use App\Services\Players\PropCalibration;
use App\Services\Players\PropProjectionSettler;
use App\Services\Players\PropProjectionWriter;
use App\Services\Players\PropProjector;
use Illuminate\Support\Facades\DB;

function recordedTeam(string $slug): Team
{
    return Team::firstOrCreate(
        ['sport' => 'nba', 'slug' => $slug],
        ['name' => ucfirst($slug), 'display_name' => ucfirst($slug)],
    );
}

/** A game, upcoming or finished. */
function recordedGame(int $n, bool $finished = false): Event
{
    return Event::firstOrCreate(
        [
            'sport' => 'nba',
            'home_team_id' => recordedTeam('rec-home')->id,
            'away_team_id' => recordedTeam('rec-away')->id,
            /*
             * Minutes rather than days for a finished game, so a large
             * index cannot push a game that is meant to be over into
             * the future - which it did, and the settler quite rightly
             * declined to grade games that had not happened.
             */
            'starts_at' => $finished
                ? now()->subDays(90)->addMinutes($n)
                : now()->addDays(2)->addMinutes($n),
        ],
        [
            'season' => (int) now()->year,
            'status' => $finished ? 'final' : 'scheduled',
            'final_home_score' => $finished ? 110 : null,
            'final_away_score' => $finished ? 104 : null,
        ],
    );
}

/** Both sides of one prop line, from one book. */
function recordedQuote(Event $event, string $player, string $market, float $line, int $over, int $under): void
{
    $book = Book::firstOrCreate(['key' => 'rec-book'], ['title' => 'Rec Book']);

    foreach ([['over', $over], ['under', $under]] as [$side, $price]) {
        DB::table('odds_snapshots')->insert([
            'event_id' => $event->id,
            'book_id' => $book->id,
            'market' => null,
            'market_key' => $market,
            'player_name' => $player,
            'side' => $side,
            'line' => $line,
            'price_american' => $price,
            'price_decimal' => $price > 0 ? 1 + ($price / 100) : 1 + (100 / abs($price)),
            'pulled_at' => now(),
            'created_at' => now(),
        ]);
    }
}

/** A player's game log, oldest first, on finished games. */
function recordedLog(string $player, string $statKey, array $values, int $offset = 0): void
{
    foreach (array_values($values) as $i => $value) {
        $game = recordedGame($offset + $i + 1, finished: true);

        DB::table('player_game_stats')->insert([
            'sport' => 'nba',
            'event_id' => $game->id,
            'team_id' => recordedTeam('rec-home')->id,
            'player_external_id' => 'ext-'.md5($player),
            'player_name' => $player,
            'played' => true,
            'stat_group' => 'all',
            'stats' => json_encode([$statKey => (string) $value]),
            'source' => 'test',
            'as_of' => $game->starts_at,
            'created_at' => now(),
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| The three guarantees
|--------------------------------------------------------------------------
*/

it('will not let a recorded projection be rewritten', function () {
    /*
     * A calibration record that can be edited measures nothing. This is
     * the entire reason the table exists, so it is enforced in the
     * database and in the model rather than by convention.
     */
    recordedLog('Logged Guard', 'points', array_fill(0, 14, 20));
    $game = recordedGame(500);
    recordedQuote($game, 'Logged Guard', 'player_points', 19.5, -110, -110);

    app(PropProjectionWriter::class)->record('nba');

    $row = PropProjection::query()->firstOrFail();

    expect(fn () => $row->update(['prob_over' => 0.99]))
        ->toThrow(AppendOnlyViolation::class)
        ->and(fn () => $row->delete())
        ->toThrow(AppendOnlyViolation::class);
});

it('refuses a projection written after the game started', function () {
    /*
     * A projection recorded once the box score was in would score
     * perfectly and mean nothing, and that failure is silent - the rows
     * look exactly like honest ones. Enforced in the database, which is
     * the only version worth having.
     */
    $game = recordedGame(1, finished: true);

    expect(fn () => PropProjection::create([
        'event_id' => $game->id,
        'sport' => 'nba',
        'market_key' => 'player_points',
        'player_key' => 'after the fact',
        'player_name' => 'After The Fact',
        'line' => 19.5,
        'projected_mean' => 22.0,
        'projected_sd' => 6.0,
        'prob_over' => 0.7,
        'prob_under' => 0.3,
        'prob_push' => 0.0,
        'family' => 'count',
        'games' => 20,
        'effective_games' => 18.0,
        'model_fingerprint' => 'whatever',
        'kickoff_at' => $game->starts_at,
        // After kickoff, which is the thing being refused.
        'projected_at' => $game->starts_at->copy()->addHour(),
        'created_at' => now(),
    ]))->toThrow(Exception::class);
});

it('does not turn one opinion into thirty when run nightly', function () {
    /*
     * The projector runs over the same board every night. Thirty copies
     * of one projection would make a small sample look like a large
     * one, which is the error a calibration figure cannot survive.
     */
    recordedLog('Repeat Guard', 'points', array_fill(0, 14, 18));
    $game = recordedGame(501);
    recordedQuote($game, 'Repeat Guard', 'player_points', 17.5, -110, -110);

    $writer = app(PropProjectionWriter::class);

    $first = $writer->record('nba');
    $second = $writer->record('nba');
    $third = $writer->record('nba');

    expect($first['recorded'])->toBe(1)
        ->and($second['recorded'])->toBe(0)
        ->and($second['existing'])->toBe(1)
        ->and($third['existing'])->toBe(1)
        ->and(PropProjection::count())->toBe(1);
});

it('files a changed model under its own fingerprint', function () {
    /*
     * Without this, lowering a half-life silently pools two different
     * models' predictions into one calibration figure that describes
     * neither - and the figure looks like more evidence rather than
     * less.
     */
    $writer = app(PropProjectionWriter::class);
    $before = $writer->fingerprint();

    config()->set('edge.props.recency_half_life_games', 7);

    expect(app(PropProjectionWriter::class)->fingerprint())->not->toBe($before);
});

/*
|--------------------------------------------------------------------------
| Grading
|--------------------------------------------------------------------------
*/

it('grades over, under and push against the line', function (float $actual, float $line, string $expected) {
    $game = recordedGame(2, finished: true);

    DB::table('player_game_stats')->insert([
        'sport' => 'nba',
        'event_id' => $game->id,
        'team_id' => null,
        'player_external_id' => 'ext-graded',
        'player_name' => 'Graded Guard',
        'played' => true,
        'stat_group' => 'all',
        'stats' => json_encode(['points' => (string) $actual]),
        'source' => 'test',
        'as_of' => $game->starts_at,
        'created_at' => now(),
    ]);

    PropProjection::create([
        'event_id' => $game->id,
        'sport' => 'nba',
        'market_key' => 'player_points',
        'player_key' => 'graded guard',
        'player_name' => 'Graded Guard',
        'line' => $line,
        'projected_mean' => 20.0,
        'projected_sd' => 6.0,
        'prob_over' => 0.5,
        'prob_under' => 0.5,
        'prob_push' => 0.0,
        'family' => 'count',
        'games' => 20,
        'effective_games' => 18.0,
        'model_fingerprint' => 'fixed',
        'kickoff_at' => $game->starts_at,
        'projected_at' => $game->starts_at->copy()->subDay(),
        'created_at' => now(),
    ]);

    app(PropProjectionSettler::class)->run();

    expect(PropProjectionResult::query()->firstOrFail()->outcome)->toBe($expected);
})->with([
    'clears it' => [24.0, 19.5, 'over'],
    'misses it' => [14.0, 19.5, 'under'],
    'lands on a whole line' => [20.0, 20.0, 'push'],
]);

it('voids a player who never took the field rather than calling it an under', function () {
    /*
     * A player with no line in the box score did not play, and most
     * books void the prop rather than settling it. Scoring it as an
     * under would flatter this model specifically, because it leans
     * under - it would be marked right for a reason it never predicted.
     */
    $game = recordedGame(3, finished: true);

    PropProjection::create([
        'event_id' => $game->id,
        'sport' => 'nba',
        'market_key' => 'player_points',
        'player_key' => 'never played',
        'player_name' => 'Never Played',
        'line' => 19.5,
        'projected_mean' => 21.0,
        'projected_sd' => 6.0,
        'prob_over' => 0.6,
        'prob_under' => 0.4,
        'prob_push' => 0.0,
        'family' => 'count',
        'games' => 20,
        'effective_games' => 18.0,
        'model_fingerprint' => 'fixed',
        'kickoff_at' => $game->starts_at,
        'projected_at' => $game->starts_at->copy()->subDay(),
        'created_at' => now(),
    ]);

    app(PropProjectionSettler::class)->run();

    $result = PropProjectionResult::query()->firstOrFail();

    expect($result->outcome)->toBe('void')
        ->and($result->actual)->toBeNull()
        // And excluded from every figure, not merely labelled.
        ->and(app(PropCalibration::class)->overall()['graded'])->toBe(0);
});

it('waits for a final box score rather than grading a game in progress', function () {
    /*
     * A game that has kicked off is not a game that has been scored,
     * and grading a prop against a number still going up would be
     * wrong in whichever direction the game was heading.
     */
    $game = Event::create([
        'sport' => 'nba',
        'home_team_id' => recordedTeam('rec-home')->id,
        'away_team_id' => recordedTeam('rec-away')->id,
        'starts_at' => now()->subHour(),
        'season' => (int) now()->year,
        'status' => 'in_progress',
    ]);

    PropProjection::create([
        'event_id' => $game->id,
        'sport' => 'nba',
        'market_key' => 'player_points',
        'player_key' => 'still playing',
        'player_name' => 'Still Playing',
        'line' => 19.5,
        'projected_mean' => 21.0,
        'projected_sd' => 6.0,
        'prob_over' => 0.6,
        'prob_under' => 0.4,
        'prob_push' => 0.0,
        'family' => 'count',
        'games' => 20,
        'effective_games' => 18.0,
        'model_fingerprint' => 'fixed',
        'kickoff_at' => $game->starts_at,
        'projected_at' => $game->starts_at->copy()->subDay(),
        'created_at' => now(),
    ]);

    $graded = app(PropProjectionSettler::class)->run();

    expect($graded['settled'])->toBe(0)
        ->and($graded['unresolved'])->toBe(1)
        ->and(PropProjectionResult::count())->toBe(0);
});

it('reads the result with the same reader that built the projection', function () {
    /*
     * A projection that took 5 from "5-12" and a grade that took 512
     * would disagree about what happened, and the calibration figure
     * would be about neither.
     */
    $game = recordedGame(4, finished: true);

    DB::table('player_game_stats')->insert([
        'sport' => 'nba',
        'event_id' => $game->id,
        'team_id' => null,
        'player_external_id' => 'ext-shooter',
        'player_name' => 'Compound Shooter',
        'played' => true,
        'stat_group' => 'all',
        'stats' => json_encode(['threePointFieldGoalsMade-threePointFieldGoalsAttempted' => '5-12']),
        'source' => 'test',
        'as_of' => $game->starts_at,
        'created_at' => now(),
    ]);

    $actual = app(PropProjector::class)->actualFor(
        $game->id, 'nba', 'player_threes', 'compound shooter'
    );

    expect($actual)->toEqualWithDelta(5.0, 1e-9);
});

/*
|--------------------------------------------------------------------------
| What the record says
|--------------------------------------------------------------------------
*/

it('scores the model against the market, not only against the truth', function () {
    /*
     * The bar, and the reason calibration alone is not enough. The
     * market is also well calibrated, and on the same games - so a
     * model is worth publishing when its log loss is lower than the
     * no-vig market price's on the same selections, which is the test
     * the game markets are held to.
     *
     * Here the market is given a flat 50% on every selection and the
     * model is given the right answer, so the model must come out
     * ahead. The point is that the comparison is made at all.
     */
    foreach (range(1, 12) as $i) {
        $game = recordedGame(600 + $i, finished: true);
        $over = $i % 2 === 0;

        DB::table('player_game_stats')->insert([
            'sport' => 'nba',
            'event_id' => $game->id,
            'team_id' => null,
            'player_external_id' => 'ext-sharp-'.$i,
            'player_name' => 'Sharp Guard'.$i,
            'played' => true,
            'stat_group' => 'all',
            'stats' => json_encode(['points' => $over ? '30' : '10']),
            'source' => 'test',
            'as_of' => $game->starts_at,
            'created_at' => now(),
        ]);

        PropProjection::create([
            'event_id' => $game->id,
            'sport' => 'nba',
            'market_key' => 'player_points',
            'player_key' => 'sharp guard'.$i,
            'player_name' => 'Sharp Guard'.$i,
            'line' => 19.5,
            'projected_mean' => $over ? 30.0 : 10.0,
            'projected_sd' => 6.0,
            'prob_over' => $over ? 0.9 : 0.1,
            'prob_under' => $over ? 0.1 : 0.9,
            'prob_push' => 0.0,
            'family' => 'count',
            'games' => 20,
            'effective_games' => 18.0,
            // The market, knowing nothing.
            'fair_prob_over' => 0.5,
            'book_count' => 3,
            'model_fingerprint' => 'fixed',
            'kickoff_at' => $game->starts_at,
            'projected_at' => $game->starts_at->copy()->subDay(),
            'created_at' => now(),
        ]);
    }

    app(PropProjectionSettler::class)->run();

    $overall = app(PropCalibration::class)->overall();

    expect($overall['graded'])->toBe(12)
        ->and($overall['with_fair_price'])->toBe(12)
        ->and($overall['model_log_loss'])->toBeLessThan($overall['market_log_loss'])
        ->and($overall['beats_market'])->toBeTrue()
        ->and($overall['brier_skill_vs_market'])->toBeGreaterThan(0.0);
});

it('says it cannot judge a market the board only quotes one side of', function () {
    /*
     * Every anytime touchdown quote collected is yes-only, so the vig
     * cannot be removed and there is no fair price to compare against.
     * The largest prop market on the board can be checked for
     * calibration and not for edge, and the figure has to say so rather
     * than quietly reporting a comparison it did not make.
     */
    $game = recordedGame(700, finished: true);

    DB::table('player_game_stats')->insert([
        'sport' => 'nba',
        'event_id' => $game->id,
        'team_id' => null,
        'player_external_id' => 'ext-lonely',
        'player_name' => 'One Sided',
        'played' => true,
        'stat_group' => 'all',
        'stats' => json_encode(['points' => '25']),
        'source' => 'test',
        'as_of' => $game->starts_at,
        'created_at' => now(),
    ]);

    PropProjection::create([
        'event_id' => $game->id,
        'sport' => 'nba',
        'market_key' => 'player_points',
        'player_key' => 'one sided',
        'player_name' => 'One Sided',
        'line' => 19.5,
        'projected_mean' => 24.0,
        'projected_sd' => 6.0,
        'prob_over' => 0.75,
        'prob_under' => 0.25,
        'prob_push' => 0.0,
        // No two-sided price, so no fair probability.
        'fair_prob_over' => null,
        'book_count' => 1,
        'family' => 'count',
        'games' => 20,
        'effective_games' => 18.0,
        'model_fingerprint' => 'fixed',
        'kickoff_at' => $game->starts_at,
        'projected_at' => $game->starts_at->copy()->subDay(),
        'created_at' => now(),
    ]);

    app(PropProjectionSettler::class)->run();

    $overall = app(PropCalibration::class)->overall();

    expect($overall['graded'])->toBe(1)
        ->and($overall['with_fair_price'])->toBe(0)
        // Calibration is still measurable; the comparison is not, and
        // null says so rather than a number standing in for it.
        ->and($overall['calibration_error'])->not->toBeNull()
        ->and($overall['model_log_loss'])->toBeNull()
        ->and($overall['beats_market'])->toBeNull();
});

it('renormalises the push out before comparing to a price', function () {
    /*
     * A projection of 48% over, 44% under and 8% push prefers the over,
     * and saying 48% understates it. Among the bets that resolve it is
     * 52%, and that is the number a price is compared against - scoring
     * 48 against a resolved outcome would mark the model down for a
     * push that did not happen.
     */
    $row = new PropProjection([
        'prob_over' => 0.48,
        'prob_under' => 0.44,
        'prob_push' => 0.08,
    ]);

    $preference = $row->preference();

    expect($preference['side'])->toBe('over')
        ->and($preference['probability'])->toEqualWithDelta(0.48 / 0.92, 1e-9);
});
