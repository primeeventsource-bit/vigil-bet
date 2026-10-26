<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| How far an unproven market may disagree with the book
|--------------------------------------------------------------------------
|
| The calibrated tier let four markets publish, and the first board it
| produced offered Philadelphia at a 27.8% edge: the model had them at
| 62.3% against a no-vig market of 33.6%. Professional edges run one to
| three points. Every market here loses to the closing line, so a model
| claiming the book is twenty-eight points wrong is the model being wrong,
| published as a recommendation.
|
| Calibration is no protection against this. It says the stated
| probabilities are right on average, and the largest disagreements with a
| sharper source are precisely where an average is least comforting.
|
| So a market that has not beaten the close is shrunk toward it. A market
| that has beaten it is not - it earned the right to disagree.
|
*/

use App\Enums\Verdict;
use App\Models\Event;
use App\Models\Team;
use App\Services\Value\ValueScanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A game nobody is injured for, so the base shrink is what is measured.
 */
function cleanGame(): Event
{
    $salt = Str::random(6);

    $team = fn (string $side) => Team::create([
        'sport' => 'nfl',
        'name' => $side.' '.$salt,
        'display_name' => $side.' '.$salt,
        'slug' => mb_strtolower($side).'-'.$salt,
    ]);

    return Event::create([
        'sport' => 'nfl',
        'home_team_id' => $team('Home')->id,
        'away_team_id' => $team('Away')->id,
        'starts_at' => now()->addDays(2),
        'season' => (int) now()->year,
        'status' => 'scheduled',
    ]);
}

/** Rule a number of players out for one side. */
function ruleOut(Event $event, string $side, int $count, string $position = 'LB'): void
{
    /*
     * The default is an ordinary position. Every test above this was
     * written to measure the size of an imbalance rather than the weight
     * of a position, and giving them a quarterback apiece would have them
     * measure something else while still passing.
     */
    $teamId = $side === 'home' ? $event->home_team_id : $event->away_team_id;

    foreach (range(1, max($count, 0)) as $i) {
        if ($i > $count) {
            break;
        }

        DB::table('injury_reports')->insert([
            'sport' => 'nfl',
            'team_id' => $teamId,
            'player_name' => $side.' Player '.$i.' '.Str::random(4),
            'position' => $position,
            'status' => 'out',
            'source' => 'test',
            'reported_at' => now()->addDay(),
            'created_at' => now(),
        ]);
    }
}

function shrink(float $model, ?float $fair, Verdict $verdict, ?Event $event = null): float
{
    $method = new ReflectionMethod(ValueScanner::class, 'shrinkToMarket');

    return $method->invoke(app(ValueScanner::class), $model, $fair, $verdict, $event ?? cleanGame());
}

it('pulls an unproven market most of the way back to the book', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    // The real numbers off the first calibrated board.
    $published = shrink(0.623, 0.336, Verdict::Calibrated);

    expect(round($published, 3))->toBe(0.408)
        // 7.2 points rather than 28.7, which is a claim the evidence carries.
        ->and(round($published - 0.336, 3))->toBe(0.072);
});

it('leaves a market that beat the closing line alone', function () {
    /*
     * The one tier that earned the right to disagree. Shrinking it would
     * throw away the only thing it has proved.
     */
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    expect(shrink(0.623, 0.336, Verdict::Proven))->toBe(0.623);
});

it('shrinks every tier that does not claim an edge', function (Verdict $verdict) {
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    expect(shrink(0.623, 0.336, $verdict))->toBeLessThan(0.623)
        ->and($verdict->claimsEdge())->toBeFalse();
})->with([
    'calibrated' => Verdict::Calibrated,
    'unproven' => Verdict::Unproven,
    'negative' => Verdict::Negative,
    'insufficient' => Verdict::Insufficient,
]);

it('shrinks a disagreement in either direction', function () {
    /*
     * Symmetry matters: a model under the market is as likely to be wrong
     * as one over it, and shrinking only the overs would bias every board
     * toward unders.
     */
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    $under = shrink(0.200, 0.500, Verdict::Calibrated);

    expect(round($under, 3))->toBe(0.425)
        ->and($under)->toBeGreaterThan(0.200);
});

it('leaves the number alone when there is no market to shrink toward', function () {
    /*
     * No consensus price means no edge is computed anyway, so there is
     * nothing to protect against and nothing to invent.
     */
    expect(shrink(0.623, null, Verdict::Calibrated))->toBe(0.623);
});

it('can be turned off, and says so in configuration rather than in code', function () {
    config()->set('edge.value.unproven_edge_shrink', 1.0);

    expect(shrink(0.623, 0.336, Verdict::Calibrated))->toBe(0.623);
});

it('collapses a market onto the book when set to zero', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.0);

    expect(shrink(0.623, 0.336, Verdict::Calibrated))->toBe(0.336);
});

it('ships with a ceiling that actually binds', function () {
    /*
     * A default of 1.0 would be the bug this exists to prevent, shipped
     * as a setting.
     */
    $configured = (float) config('edge.value.unproven_edge_shrink');

    expect($configured)->toBeGreaterThan(0.0)
        ->and($configured)->toBeLessThan(1.0);
});

/*
|--------------------------------------------------------------------------
| Injuries the model has never seen
|--------------------------------------------------------------------------
|
| The engine's probability is a power rating. A book reprices within
| minutes of a quarterback being ruled out, so a lopsided absence list is
| information the market holds and this model does not - and the response
| is to disagree with the market less, not to invent a points-per-injury
| coefficient nobody measured.
|
*/

it('disagrees with the market less when one side is missing more players', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.25);
    config()->set('edge.value.absence_imbalance_weight', 0.25);

    $game = cleanGame();
    $even = shrink(0.623, 0.336, Verdict::Calibrated, $game);

    $lopsided = cleanGame();
    ruleOut($lopsided, 'home', 5);

    expect(shrink(0.623, 0.336, Verdict::Calibrated, $lopsided))
        ->toBeLessThan($even);
});

it('treats absences on both sides as the ordinary attrition they are', function () {
    /*
     * Every upcoming NFL game had two to six players out per side when
     * this was written. Counting that as a crisis would shrink every pick
     * to nothing.
     */
    config()->set('edge.value.unproven_edge_shrink', 0.25);
    config()->set('edge.value.absence_imbalance_weight', 0.25);

    $game = cleanGame();
    ruleOut($game, 'home', 5);
    ruleOut($game, 'away', 5);

    expect(round(shrink(0.623, 0.336, Verdict::Calibrated, $game), 3))
        ->toBe(round(shrink(0.623, 0.336, Verdict::Calibrated, cleanGame()), 3));
});

it('shrinks further the more lopsided the list gets', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.25);
    config()->set('edge.value.absence_imbalance_weight', 0.25);

    $one = cleanGame();
    ruleOut($one, 'home', 1);

    $five = cleanGame();
    ruleOut($five, 'home', 5);

    expect(shrink(0.623, 0.336, Verdict::Calibrated, $five))
        ->toBeLessThan(shrink(0.623, 0.336, Verdict::Calibrated, $one));
});

it('ignores a report filed after kickoff, which is the leakage rule', function () {
    /*
     * A scan re-run over a finished game would otherwise count the report
     * filed at half time and look clairvoyant.
     */
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    $game = cleanGame();

    DB::table('injury_reports')->insert([
        'sport' => 'nfl',
        'team_id' => $game->home_team_id,
        'player_name' => 'Ruled Out At Half Time',
        'status' => 'out',
        'source' => 'test',
        'reported_at' => $game->starts_at->copy()->addHours(2),
        'created_at' => now(),
    ]);

    expect(round(shrink(0.623, 0.336, Verdict::Calibrated, $game), 3))
        ->toBe(round(shrink(0.623, 0.336, Verdict::Calibrated, cleanGame()), 3));
});

it('leaves a proven market alone when both teams are whole', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    $game = cleanGame();
    ruleOut($game, 'home', 3);
    ruleOut($game, 'away', 3);

    // Ordinary attrition, evenly spread. Nothing here is information the
    // book has and the rating does not.
    expect(shrink(0.623, 0.336, Verdict::Proven, $game))->toBe(0.623);
});

/*
 * This file used to assert the opposite: that a proven market is never
 * shrunk, however lopsided the game. That was one decision doing two
 * jobs, and they come apart.
 *
 * Beating the closing line is a fact about the model across ordinary
 * fixtures. It says nothing about a fixture missing a quarterback,
 * because the rating has never seen a team sheet and the measurement
 * that proved it contains almost no such games. So the tier still buys
 * exemption from the tier shrink, and buys nothing at all from the
 * absence shrink - which asks a different question.
 */
it('shrinks even a proven market when one side is missing more players', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    $game = cleanGame();
    ruleOut($game, 'home', 4);

    $published = shrink(0.623, 0.336, Verdict::Proven, $game);

    expect($published)->toBeLessThan(0.623)
        ->and($published)->toBeGreaterThan(0.336);
});

it('counts a quarterback for more than a linebacker', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    $withQb = cleanGame();
    ruleOut($withQb, 'home', 1, 'QB');

    $withLb = cleanGame();
    ruleOut($withLb, 'home', 1, 'LB');

    /*
     * One absence either way, so a head count cannot tell these apart.
     * The market certainly can, and this number exists to say how much
     * the market knows that the rating does not.
     */
    expect(shrink(0.623, 0.336, Verdict::Calibrated, $withQb))
        ->toBeLessThan(shrink(0.623, 0.336, Verdict::Calibrated, $withLb));
});

it('treats a missing quarterback as worse than several missing linebackers', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    $qb = cleanGame();
    ruleOut($qb, 'home', 1, 'QB');

    $four = cleanGame();
    ruleOut($four, 'home', 4, 'LB');

    expect(shrink(0.623, 0.336, Verdict::Calibrated, $qb))
        ->toBeLessThan(shrink(0.623, 0.336, Verdict::Calibrated, $four));
});

it('reads the weights from configuration rather than from code', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.25);
    config()->set('edge.value.absence_position_weights', ['*' => 1.0, 'QB' => 1.0]);

    $qb = cleanGame();
    ruleOut($qb, 'home', 1, 'QB');

    $lb = cleanGame();
    ruleOut($lb, 'home', 1, 'LB');

    // Flattened on purpose: these are a policy, and a policy that cannot
    // be changed without a deploy is a constant wearing a disguise.
    expect(shrink(0.623, 0.336, Verdict::Calibrated, $qb))
        ->toBe(shrink(0.623, 0.336, Verdict::Calibrated, $lb));
});

it('counts a position it has never heard of as an ordinary one', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    $known = cleanGame();
    ruleOut($known, 'home', 1, 'LB');

    $blank = cleanGame();
    ruleOut($blank, 'home', 1, '');

    /*
     * Not zero. A feed that stops sending positions should make this
     * number less certain, not quietly stop shrinking anything.
     */
    expect(shrink(0.623, 0.336, Verdict::Calibrated, $blank))
        ->toBe(shrink(0.623, 0.336, Verdict::Calibrated, $known));
});
