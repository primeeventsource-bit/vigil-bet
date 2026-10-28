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

    // The real numbers off the first calibrated board, read here
    // against the unproven tier: `calibrated` has its own knob now,
    // and this test is about this one.
    $published = shrink(0.623, 0.336, Verdict::Unproven);

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

    $under = shrink(0.200, 0.500, Verdict::Unproven);

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

    expect(shrink(0.623, 0.336, Verdict::Unproven))->toBe(0.623);
});

it('collapses a market onto the book when set to zero', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.0);

    expect(shrink(0.623, 0.336, Verdict::Unproven))->toBe(0.336);
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

/*
 * What the position weight alone gets wrong.
 *
 * Philadelphia on 26 October: Jalen Hurts out since the 11th, by which
 * point the provider had him fourth on its own chart and Tanner McKee
 * had started the game in between. An absence the team has already
 * played around twice is in the price and largely in the rating, and
 * shrinking again for it answers news a fortnight old.
 */

function chartRank(Event $event, string $side, string $playerExternalId, int $rank, string $position = 'QB'): void
{
    DB::table('depth_chart_entries')->insert([
        'sport' => 'nfl',
        'team_id' => $side === 'home' ? $event->home_team_id : $event->away_team_id,
        'player_external_id' => $playerExternalId,
        'position' => $position,
        'formation' => '3WR 1TE',
        'rank' => $rank,
        'source' => 'test',
        'as_of' => now()->subDay(),
        'created_at' => now()->subDay(),
    ]);
}

function ruleOutPlayer(Event $event, string $side, string $playerExternalId, string $position = 'QB'): void
{
    DB::table('injury_reports')->insert([
        'sport' => 'nfl',
        'team_id' => $side === 'home' ? $event->home_team_id : $event->away_team_id,
        'player_external_id' => $playerExternalId,
        'player_name' => 'Player '.$playerExternalId,
        'position' => $position,
        'status' => 'out',
        'source' => 'test',
        'reported_at' => now()->addDay(),
        'created_at' => now(),
    ]);
}

it('shrinks less for a fourth-choice quarterback than a first-choice one', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    $first = cleanGame();
    chartRank($first, 'home', '1001', 1);
    ruleOutPlayer($first, 'home', '1001');

    $fourth = cleanGame();
    chartRank($fourth, 'home', '2002', 4);
    ruleOutPlayer($fourth, 'home', '2002');

    // Both are a quarterback out. Only one of them is the team's
    // quarterback.
    expect(shrink(0.623, 0.336, Verdict::Calibrated, $fourth))
        ->toBeGreaterThan(shrink(0.623, 0.336, Verdict::Calibrated, $first));
});

it('counts a player it has no chart for in full', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    $charted = cleanGame();
    chartRank($charted, 'home', '1001', 1);
    ruleOutPlayer($charted, 'home', '1001');

    $unknown = cleanGame();
    ruleOutPlayer($unknown, 'home', '3003');

    /*
     * A chart we have not fetched is not evidence that an absence is
     * cheap. Guessing cheap is the one direction that could talk the
     * shrink out of firing when it should.
     */
    expect(shrink(0.623, 0.336, Verdict::Calibrated, $unknown))
        ->toBe(shrink(0.623, 0.336, Verdict::Calibrated, $charted));
});

it('ignores a chart published after kickoff', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    $game = cleanGame();
    ruleOutPlayer($game, 'home', '1001');

    DB::table('depth_chart_entries')->insert([
        'sport' => 'nfl',
        'team_id' => $game->home_team_id,
        'player_external_id' => '1001',
        'position' => 'QB',
        'formation' => '3WR 1TE',
        'rank' => 4,
        'source' => 'test',
        // After the game. A team sheet published then was partly chosen
        // because of how the game went.
        'as_of' => now()->addDays(3),
        'created_at' => now()->addDays(3),
    ]);

    $unknown = cleanGame();
    ruleOutPlayer($unknown, 'home', '4004');

    // The late chart is not read, so this is the unknown-player case.
    expect(shrink(0.623, 0.336, Verdict::Calibrated, $game))
        ->toBe(shrink(0.623, 0.336, Verdict::Calibrated, $unknown));
});

/*
 * The stricter half of the leakage rule.
 *
 * The feed publishes availability only as it stands now, so the date on
 * a row is not when we could have known it. A report dated last week but
 * fetched tonight is tonight's knowledge, and reading it for a game that
 * has already kicked off is reading the list of injuries that turned out
 * to last - a fact from after the game.
 */
it('ignores a report fetched after kickoff, however it is dated', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    $game = cleanGame();

    DB::table('injury_reports')->insert([
        'sport' => 'nfl',
        'team_id' => $game->home_team_id,
        'player_name' => 'Backdated '.Str::random(4),
        'position' => 'QB',
        'status' => 'out',
        'source' => 'test',
        // Dated before the game and fetched long after it.
        'reported_at' => now()->addHours(1),
        'created_at' => now()->addDays(5),
    ]);

    // Nothing was knowable, so nothing is shrunk beyond the tier.
    expect(shrink(0.623, 0.336, Verdict::Calibrated, $game))
        ->toBe(shrink(0.623, 0.336, Verdict::Calibrated, cleanGame()));
});

it('ignores a report with no fetch time at all', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.25);

    $game = cleanGame();

    DB::table('injury_reports')->insert([
        'sport' => 'nfl',
        'team_id' => $game->home_team_id,
        'player_name' => 'Undated '.Str::random(4),
        'position' => 'QB',
        'status' => 'out',
        'source' => 'test',
        'reported_at' => now()->addHours(1),
        // A row that cannot say when it was known is not evidence of
        // what was known.
        'created_at' => null,
    ]);

    expect(shrink(0.623, 0.336, Verdict::Calibrated, $game))
        ->toBe(shrink(0.623, 0.336, Verdict::Calibrated, cleanGame()));
});

/*
|--------------------------------------------------------------------------
| The calibrated tier
|--------------------------------------------------------------------------
|
| The middle tier exists to undo a circularity, and these hold it there.
|
| OpinionBar refuses to ask whether a model beats the closing line,
| because waiting for one means publishing nothing while the evidence
| that would settle it only accumulates once something is published.
| This shrink then asked that exact question anyway, four files away, and
| cut every calibrated market to a quarter - so an opinion needed a 12%
| raw edge before the injury divisor touched it, and the engine published
| nothing for two days while every component reported itself healthy.
|
*/

it('gives a calibrated market more voice than an unproven one', function () {
    /*
     * The distinction the tier exists for. Calibration is a measured
     * claim - a stated 74% comes in at roughly 74% - and it is the claim
     * an opinion actually makes. A market that has earned it has earned
     * more than a quarter of its own number.
     */
    config()->set('edge.value.unproven_edge_shrink', 0.25);
    config()->set('edge.value.calibrated_edge_shrink', 0.50);

    $calibrated = shrink(0.623, 0.336, Verdict::Calibrated);
    $unproven = shrink(0.623, 0.336, Verdict::Unproven);

    expect($calibrated)->toBeGreaterThan($unproven)
        // Half the distance from the book rather than a quarter.
        ->and(round($calibrated, 3))->toBe(0.480)
        ->and(round($calibrated - 0.336, 3))->toBe(0.144);
});

it('still keeps a calibrated market short of its own number', function () {
    /*
     * The other side of it. Calibrated is not proven: it does not beat
     * the price, and a tier that stopped shrinking it would be selling
     * an edge nobody has measured.
     */
    config()->set('edge.value.calibrated_edge_shrink', 0.50);

    expect(shrink(0.623, 0.336, Verdict::Calibrated))
        ->toBeLessThan(0.623)
        ->toBeGreaterThan(0.336);
});

it('ranks the three tiers in the order the evidence does', function () {
    config()->set('edge.value.unproven_edge_shrink', 0.25);
    config()->set('edge.value.calibrated_edge_shrink', 0.50);

    $proven = shrink(0.623, 0.336, Verdict::Proven);
    $calibrated = shrink(0.623, 0.336, Verdict::Calibrated);
    $unproven = shrink(0.623, 0.336, Verdict::Unproven);

    expect($proven)->toBeGreaterThan($calibrated)
        ->and($calibrated)->toBeGreaterThan($unproven);
});

it('keeps the calibrated tier between the other two in configuration', function () {
    /*
     * A shipped default of 1.0 here would silently promote every
     * calibrated market to proven, which is the claim this whole file
     * exists to prevent; 0.25 would put the circularity back.
     */
    $unproven = (float) config('edge.value.unproven_edge_shrink');
    $calibrated = (float) config('edge.value.calibrated_edge_shrink');

    expect($calibrated)->toBeGreaterThan($unproven,
        'A calibrated market is being shrunk as hard as an unproven one, which is'
        .' what silenced the engine: nothing is proven, so nothing publishes, so'
        .' nothing ever accumulates the evidence that would prove it.')
        ->and($calibrated)->toBeLessThan(1.0,
            'A calibrated market does not beat the price and must not be trusted as if it did.');
});

it('does not let a calibrated market skip the team sheet', function (): void {
    /*
     * Whatever the tier, a game missing a quarterback on one side is not
     * one of the games the model was measured on.
     */
    config()->set('edge.value.calibrated_edge_shrink', 0.50);

    config()->set('edge.value.absence_imbalance_weight', 0.25);

    $whole = shrink(0.623, 0.336, Verdict::Calibrated, cleanGame());

    $lopsided = cleanGame();
    ruleOut($lopsided, 'home', 5);

    expect(shrink(0.623, 0.336, Verdict::Calibrated, $lopsided))->toBeLessThan($whole);
});
