<?php

declare(strict_types=1);

use App\Models\Opinion;
use App\Models\PublishedPick;
use App\Services\Record\OpinionPublisher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Publishing an opinion
|--------------------------------------------------------------------------
|
| The weaker of the two published claims. A pick says this market has
| beaten the closing line; an opinion says only that the model's stated
| probabilities land close to the truth.
|
| The tests that matter are the boundaries: that it never writes to the
| public record, that it refuses a market whose confidence figures are
| wrong, and that it cannot be made to publish a game that has started.
|
*/

it('publishes an opinion with the confidence it stated', function () {
    $bet = opinionCandidate();

    expect(app(OpinionPublisher::class)->run()['published'])->toBe(1);

    $opinion = Opinion::sole();

    expect($opinion->price_american)->toBe(-110)
        ->and($opinion->confidencePercent())->toBe(62)
        // What was known about the model when it went out, kept with the
        // row so a later drift cannot rewrite this opinion's standing.
        ->and($opinion->calibration_error_at_publish)->toBe(0.0335)
        ->and($opinion->model_predictions_at_publish)->toBe(956);
});

it('never writes to the public record', function () {
    /*
     * The boundary that matters most. published_picks says of itself
     * that it holds picks sold from a proven market and nothing else,
     * and the public record is read on that promise.
     */
    opinionCandidate();

    app(OpinionPublisher::class)->run();

    expect(Opinion::count())->toBe(1)
        ->and(PublishedPick::count())->toBe(0);
});

it('refuses a market whose confidence figures are wrong', function () {
    /*
     * Every spreads model in the engine is off by nine points or more.
     * Publishing one would be publishing a number known to be false,
     * however good the bet looked on the merits.
     */
    $model = publishableModel('nba', 'spreads', [
        'predictions' => 1056,
        'calibration_error' => 0.1038,
    ]);

    opinionCandidate(['sport' => 'nba', 'market' => 'spreads', 'line' => -3.5], model: $model);

    $outcome = app(OpinionPublisher::class)->run();

    expect($outcome['published'])->toBe(0)
        ->and($outcome['skipped']['market_not_publishable'])->toBe(1)
        ->and(Opinion::count())->toBe(0);
});

it('refuses a market with too few measured predictions', function () {
    $model = publishableModel('ncaaf', 'spreads', [
        'predictions' => 61,
        'calibration_error' => 0.01,
    ]);

    opinionCandidate(['sport' => 'ncaaf', 'market' => 'spreads', 'line' => -7.0], model: $model);

    expect(app(OpinionPublisher::class)->run()['published'])->toBe(0);
});

it('leaves a bet the scanner would not take alone', function () {
    // shadow_recommendation is the opinion. A pass on the merits is a
    // pass, whatever the market's calibration says.
    opinionCandidate(['shadow_recommendation' => 'pass', 'shadow_pass_reason' => 'negative_edge']);

    expect(app(OpinionPublisher::class)->run()['published'])->toBe(0)
        ->and(Opinion::count())->toBe(0);
});

it('refuses a game inside the playable lead', function () {
    /*
     * Four minutes before kickoff is technically pre-kickoff and
     * practically unplayable, and an accuracy figure built from those
     * would be true and misleading at once.
     */
    opinionCandidate(hoursToKickoff: 0);

    $outcome = app(OpinionPublisher::class)->run();

    /*
     * Not a candidate at all, rather than a candidate that was fetched
     * and then refused.
     *
     * The distinction is the whole of the fix: the query carries a
     * two-hundred row ceiling, the board is rescanned hourly, and every
     * scan leaves another row for a selection that is still bet-worthy.
     * Those rows go on sorting to the top of `flagged_at desc` long
     * after the game has started, so the ceiling filled with games that
     * could never publish - a real run returned `published 0, too late
     * 200` while anything playable sat below the cut.
     */
    expect($outcome['published'])->toBe(0)
        ->and($outcome['candidates'])->toBe(0)
        ->and($outcome['skipped']['too_late'])->toBe(0);
});

it('does not spend the candidate ceiling on opinions it has already published', function () {
    /*
     * The companion to the above, for the other way the ceiling used to
     * be wasted. A published selection stays bet-worthy and keeps being
     * rescanned, and the publisher's only defence was to fetch the row,
     * attempt the insert, and let the unique index refuse it.
     *
     * The index is still there and still the only thing that can settle
     * a race. It should simply not be load-bearing once an hour.
     */
    opinionCandidate();

    $publisher = app(OpinionPublisher::class);

    expect($publisher->run())
        ->published->toBe(1)
        ->candidates->toBe(1);

    expect($publisher->run())
        ->published->toBe(0)
        ->candidates->toBe(0)
        ->and(Opinion::count())->toBe(1);
});

it('publishes a selection once, however often it runs', function () {
    opinionCandidate();

    $publisher = app(OpinionPublisher::class);

    expect($publisher->run()['published'])->toBe(1);

    // A rescan reaches the same game every hour with its own value_bets
    // row, which is how the shadow record came to hold five copies of
    // one selection before the guard existed.
    $second = $publisher->run();

    expect($second['published'])->toBe(0)
        ->and(Opinion::count())->toBe(1);
});

it('refuses a second opinion on one selection in the database', function () {
    // The application check is the one that runs; this is the one that
    // holds when something writes the row another way.
    $bet = opinionCandidate();
    app(OpinionPublisher::class)->run();

    $first = Opinion::sole();

    expect(rejectedByDatabase(fn () => Opinion::create([
        'event_id' => $first->event_id,
        'sport' => $first->sport,
        'market' => $first->market,
        'side' => $first->side,
        'line' => $first->line,
        'selection' => $first->selection,
        'price_american' => -115,
        'model_prob' => 0.61,
        'kickoff_at' => $first->kickoff_at,
        'published_at' => now(),
        'created_at' => now(),
    ])))->toThrow(QueryException::class);

    expect(Opinion::count())->toBe(1);
});

it('refuses to be edited or deleted once published', function () {
    opinionCandidate();
    app(OpinionPublisher::class)->run();

    $opinion = Opinion::sole();

    expect(fn () => $opinion->update(['model_prob' => 0.99]))
        ->toThrow(RuntimeException::class, 'append-only');

    expect(fn () => $opinion->delete())
        ->toThrow(RuntimeException::class, 'append-only');

    expect(Opinion::sole()->model_prob)->toBe(0.62);
});

it('refuses a row dated after kickoff, in the database', function () {
    /*
     * The rule that makes an accuracy figure worth anything. An opinion
     * that could be written once the result was known would answer
     * nothing, and would be worse than nothing shown beside a win rate.
     */
    $event = engineGame('Detroit Red Wings', 'Boston Bruins', ['sport' => 'nhl']);

    expect(rejectedByDatabase(fn () => DB::table('opinions')->insert([
        'public_id' => (string) Str::ulid(),
        'event_id' => $event->id,
        'sport' => 'nhl',
        'market' => 'h2h',
        'side' => 'home',
        'selection' => 'Detroit Red Wings',
        'price_american' => -110,
        'model_prob' => 0.62,
        'kickoff_at' => now()->subHour(),
        'published_at' => now(),
        'created_at' => now(),
    ])))->toThrow(QueryException::class);

    /*
     * The exception's text is not asserted. SQLite raises the
     * trigger's own wording and Postgres names the violated
     * constraint, so pinning either passes on one engine and fails
     * on the other. What the rule promises is that the row is not
     * there, which is the same sentence on both.
     */
    expect(Opinion::count())->toBe(0);
});
