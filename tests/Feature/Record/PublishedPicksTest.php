<?php

declare(strict_types=1);

use App\Models\PickResult;
use App\Models\PublishedPick;
use App\Models\VlEvent;
use App\Models\VlOddsDisplay;
use App\Services\Record\PickPublisher;
use App\Services\Record\PickSettler;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| §13: "A published pick timestamped after kickoff is rejected by the
| database."
|--------------------------------------------------------------------------
|
| By the database. Not by a validator, not by a model observer - because
| those can be bypassed by a console command, a seeder or an import
| script written next year by somebody who never read this file.
|
*/

it('refuses a pick published after kickoff, at the database', function () {
    expect(rejectedByDatabase(fn () => PublishedPick::create([
        'edge_lab_event_id' => 1,
        'sport' => 'nfl', 'market' => 'h2h', 'side' => 'home', 'selection' => 'Chiefs',
        'price_american' => -110,
        'kickoff_at' => now()->subHour(),
        // One hour too late. This is the row that would turn a record
        // into a lie, and the table will not hold it.
        'published_at' => now(),
        'created_at' => now(),
    ])))->toThrow(QueryException::class);

    expect(PublishedPick::count())->toBe(0);
});

it('refuses a pick published at exactly kickoff', function () {
    $at = now();

    // Not a rounding question. "Before kickoff" has to mean before.
    expect(rejectedByDatabase(fn () => PublishedPick::create([
        'edge_lab_event_id' => 1,
        'sport' => 'nfl', 'market' => 'h2h', 'side' => 'home', 'selection' => 'Chiefs',
        'price_american' => -110,
        'kickoff_at' => $at,
        'published_at' => $at,
        'created_at' => now(),
    ])))->toThrow(QueryException::class);
});

it('accepts a pick published before kickoff', function () {
    PublishedPick::create([
        'edge_lab_event_id' => 1,
        'sport' => 'nfl', 'market' => 'h2h', 'side' => 'home', 'selection' => 'Chiefs',
        'price_american' => -110,
        'kickoff_at' => now()->addHours(3),
        'published_at' => now(),
        'created_at' => now(),
    ]);

    expect(PublishedPick::count())->toBe(1)
        // And it has its permanent URL.
        ->and(PublishedPick::sole()->public_id)->not->toBeNull();
});

it('never rewrites or deletes a published pick', function () {
    $pick = PublishedPick::create([
        'edge_lab_event_id' => 1,
        'sport' => 'nfl', 'market' => 'h2h', 'side' => 'home', 'selection' => 'Chiefs',
        'price_american' => -110,
        'kickoff_at' => now()->addHours(3),
        'published_at' => now(),
        'created_at' => now(),
    ]);

    // The whole claim rests on this. A record that can be edited after
    // the game is not evidence of anything.
    expect(fn () => $pick->update(['price_american' => 500]))
        ->toThrow(RuntimeException::class, 'append-only');

    expect(fn () => $pick->delete())
        ->toThrow(RuntimeException::class, 'append-only');
});

/* ----------------------------------------------------------------------
 | The publisher
 ---------------------------------------------------------------------- */

it('publishes a proven pick with the price and probability it had', function () {
    publishableBoard(['edge_at_price' => 0.047, 'best_price_american' => -108]);

    app(PickPublisher::class)->run();

    $pick = PublishedPick::sole();

    expect($pick->price_american)->toBe(-108)
        ->and($pick->model_prob)->toBe(0.62)
        ->and($pick->edge_at_price)->toBe(0.047)
        ->and($pick->matchup)->toBe('Buffalo Bills at Kansas City Chiefs')
        // Published strictly before kickoff, which the constraint
        // enforces and this confirms from the other direction.
        ->and($pick->published_at->isBefore($pick->kickoff_at))->toBeTrue();
});

it('never publishes from a market that is not proven', function () {
    publishableBoard(['market_verdict' => 'unproven', 'recommendation' => 'pass']);

    app(PickPublisher::class)->run();

    // The gate, again, at the one write that cannot be taken back.
    expect(PublishedPick::count())->toBe(0);
});

it('does not publish a game that is about to start', function () {
    publishableBoard([], hoursToKickoff: 0);

    $result = app(PickPublisher::class)->run();

    // Technically pre-kickoff and practically unplayable. A record built
    // from those would be true and misleading at once.
    expect(PublishedPick::count())->toBe(0)
        ->and($result['skipped']['too_late'])->toBe(1);
});

it('does not publish the same board entry twice, however often it runs', function () {
    publishableBoard();

    app(PickPublisher::class)->run();
    app(PickPublisher::class)->run();
    app(PickPublisher::class)->run();

    // A record that can be padded by re-running a command is not a
    // record.
    expect(PublishedPick::count())->toBe(1);
});

it('does not publish a pick with no price to check it against', function () {
    publishableBoard(['best_price_american' => null]);

    $result = app(PickPublisher::class)->run();

    // A record entry with no price is not a claim anybody can check,
    // which makes it worse than no entry.
    expect(PublishedPick::count())->toBe(0)
        ->and($result['skipped']['no_price'])->toBe(1);
});

it('does not publish a pick whose game has not been mirrored', function () {
    provenEntry(['edge_lab_event_id' => 4242]);

    $result = app(PickPublisher::class)->run();

    expect(PublishedPick::count())->toBe(0)
        ->and($result['skipped']['no_event'])->toBe(1);
});

/* ----------------------------------------------------------------------
 | Settlement
 ---------------------------------------------------------------------- */

function settleWith(int $home, int $away): void
{
    VlEvent::create([
        'edge_lab_id' => 900,
        'sport' => 'nfl',
        'starts_at' => now()->subHours(4),
        'status' => 'final',
        'home_name' => 'Kansas City Chiefs',
        'away_name' => 'Buffalo Bills',
        'final_home_score' => $home,
        'final_away_score' => $away,
        'completed_at' => now()->subHour(),
        'payload' => [],
        'digest' => 'final900',
        'observed_at' => now(),
    ]);

    app(PickSettler::class)->run();
}

it('settles a published pick in units, from the final score', function () {
    publishableBoard(['best_price_american' => 150]);
    app(PickPublisher::class)->run();

    settleWith(home: 27, away: 24);

    $result = PickResult::sole();

    // +150 returns 1.5 units of profit on a one-unit stake.
    expect($result->status)->toBe('won')
        ->and($result->units)->toBe(1.5)
        ->and($result->final_home_score)->toBe(27);
});

it('records a loss as minus one unit', function () {
    publishableBoard(['best_price_american' => 150, 'side' => 'away']);
    app(PickPublisher::class)->run();

    settleWith(home: 27, away: 24);

    // Flat staking: a loss is one unit however long the price was.
    expect(PickResult::sole()->units)->toBe(-1.0);
});

it('records a push as zero units and keeps it in the record', function () {
    publishableBoard(['market' => 'spreads', 'side' => 'home', 'line' => -3]);
    app(PickPublisher::class)->run();

    settleWith(home: 27, away: 24);

    expect(PickResult::sole()->status)->toBe('push')
        ->and(PickResult::sole()->units)->toBe(0.0)
        // A push was a prediction that got tested, so it counts toward
        // the sample even though it made nothing.
        ->and(PublishedPick::query()->settled()->count())->toBe(1);
});

it('leaves a pick unsettled when the game has no final score', function () {
    publishableBoard();
    app(PickPublisher::class)->run();

    $result = app(PickSettler::class)->run();

    // An unanswered pick appearing as unanswered is the whole reason
    // this page is worth reading.
    expect(PickResult::count())->toBe(0)
        ->and($result['skipped'])->toBe(1);
});

it('settles each pick once, however often the settler runs', function () {
    publishableBoard(['best_price_american' => 150]);
    app(PickPublisher::class)->run();

    settleWith(home: 27, away: 24);
    app(PickSettler::class)->run();
    app(PickSettler::class)->run();

    // A pick settled twice would appear twice in the record.
    expect(PickResult::count())->toBe(1);
});

it('never rewrites a result', function () {
    publishableBoard(['best_price_american' => 150]);
    app(PickPublisher::class)->run();
    settleWith(home: 27, away: 24);

    expect(fn () => PickResult::sole()->update(['units' => 99]))
        ->toThrow(RuntimeException::class, 'append-only');
});

it('records closing line value from a quote stored before kickoff', function () {
    publishableBoard(['best_price_american' => -110, 'best_book' => 'draftkings']);
    app(PickPublisher::class)->run();

    VlOddsDisplay::create([
        'edge_lab_event_id' => 900, 'book' => 'draftkings', 'book_title' => 'DraftKings',
        'market' => 'h2h', 'side' => 'home', 'price_american' => -140,
        'quoted_at' => now()->subHours(5),
        'digest' => 'q900', 'observed_at' => now(),
    ]);

    settleWith(home: 27, away: 24);

    // Taken at -110, closed at -140: the market came to agree.
    expect(PickResult::sole()->closing_price_american)->toBe(-140)
        ->and(PickResult::sole()->clv)->toBeGreaterThan(0);
});

it('leaves closing line value unmeasured rather than estimating it', function () {
    publishableBoard(['best_price_american' => 150]);
    app(PickPublisher::class)->run();

    settleWith(home: 27, away: 24);

    // No stored quote, so no figure. A closing price fetched after the
    // fact would be a number nobody recorded at the time, and the whole
    // point of this column is that it was.
    expect(PickResult::sole()->clv)->toBeNull();
});
