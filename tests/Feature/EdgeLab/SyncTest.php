<?php

declare(strict_types=1);

use App\Enums\Verdict;
use App\Jobs\EdgeLab\SyncBoard;
use App\Jobs\EdgeLab\SyncEvents;
use App\Jobs\EdgeLab\SyncModels;
use App\Models\SyncRun;
use App\Models\VlBoardEntry;
use App\Models\VlEvent;
use App\Models\VlModel;

/*
|--------------------------------------------------------------------------
| The projection, against the engine's own tables
|--------------------------------------------------------------------------
|
| These tests used to fake HTTP. The engine ran as a second deployment and
| the product read it over a private API, so every assertion here was made
| against a response typed out in a fixture file - which meant the suite
| could only ever prove that the product agreed with the fixture.
|
| Nothing is faked now. Each test writes a real ValueBet, Event or
| ModelVersion and then asserts the mirror says what that row says. Where
| the old tests compared against a hardcoded 0.58, these compare against
| the engine record itself, so a column the projection silently stops
| copying fails the test instead of matching a number somebody typed.
|
*/

beforeEach(function () {
    config()->set('edgelab.enabled', true);
});

it('mirrors the board exactly as the engine judged it', function () {
    $bet = engineBoardEntry();

    dispatch_sync(new SyncBoard);

    $entry = VlBoardEntry::firstOrFail();

    /*
     * Compared against the engine row rather than against literals.
     * Every number here is the engine's and nothing is recomputed on this
     * side, and the way to test that claim is to assert the two agree -
     * not to assert they both equal a third value in a fixture.
     */
    expect($entry->edge_lab_id)->toBe($bet->id)
        ->and($entry->edge_lab_event_id)->toBe($bet->event_id)
        ->and((float) $entry->model_prob)->toBe((float) $bet->model_prob)
        ->and((float) $entry->fair_prob)->toBe((float) $bet->fair_prob)
        ->and((float) $entry->edge_at_price)->toBe((float) $bet->edge_at_price)
        ->and($entry->recommendation)->toBe($bet->recommendation->value)
        ->and($entry->pass_reason)->toBe($bet->pass_reason->value)
        ->and($entry->market_verdict)->toBe($bet->market_verdict->value)
        ->and($entry->best_price_american)->toBe($bet->best_price_american)
        // Read off the related book, which is the join the projection has
        // to do and the one a direct column copy would miss.
        ->and($entry->best_book)->toBe('hardrockbet')
        ->and($entry->best_book_title)->toBe('Hard Rock Bet');
});

it('keeps the passes, which are most of the board', function () {
    $scan = engineScan();

    engineBoardEntry($scan);
    engineBoardEntry($scan, null, ['pass_reason' => 'no_price']);

    dispatch_sync(new SyncBoard);

    expect(VlBoardEntry::count())->toBe(2)
        ->and(VlBoardEntry::query()->passes()->count())->toBe(2);
});

it('records a run for every sync, with what it fetched and what was new', function () {
    $scan = engineScan();
    engineBoardEntry($scan);
    $second = engineBoardEntry($scan);

    dispatch_sync(new SyncBoard);

    $run = SyncRun::firstOrFail();

    expect($run->feed)->toBe('board')
        ->and($run->status)->toBe('success')
        ->and($run->records_fetched)->toBe(2)
        ->and($run->records_written)->toBe(2)
        ->and($run->cursor_after)->toBe($second->id)
        ->and($run->finished_at)->not->toBeNull();
});

it('resumes from where the last successful run finished', function () {
    /*
     * The engine appends to the board; it never rewrites a judged
     * candidate. So a second scan arrives as new ids, and the cursor is
     * what stops the product re-reading the whole history every hour.
     */
    $scan = engineScan();
    $first = engineBoardEntry($scan);

    dispatch_sync(new SyncBoard);

    expect(SyncRun::cursorFor('board'))->toBe($first->id);

    $second = engineBoardEntry($scan);

    dispatch_sync(new SyncBoard);

    expect(VlBoardEntry::count())->toBe(2)
        ->and(SyncRun::cursorFor('board'))->toBe($second->id)
        // One row, not two: the first was already mirrored and the cursor
        // meant it was never read a second time.
        ->and(SyncRun::query()->latestFirst()->first()->records_fetched)->toBe(1);
});

it('writes nothing when a quiet tick re-reads what it already has', function () {
    // The models feed deliberately re-reads from the start every time, so
    // it is the one that would append a duplicate every night without the
    // digest to stop it.
    engineModelVersion();

    dispatch_sync(new SyncModels);
    expect(VlModel::count())->toBe(1);

    dispatch_sync(new SyncModels);

    expect(VlModel::count())->toBe(1)
        ->and(SyncRun::query()->latestFirst()->first()->records_written)->toBe(0);
});

it('mirrors the schedule and the models', function () {
    $game = engineGame('Buffalo Bills', 'Miami Dolphins');
    engineModelVersion();

    dispatch_sync(new SyncEvents);
    dispatch_sync(new SyncModels);

    $event = VlEvent::firstOrFail();
    $model = VlModel::firstOrFail();

    expect($event->edge_lab_id)->toBe($game->id)
        ->and($event->home_name)->toBe('Buffalo Bills')
        ->and($event->away_abbreviation)->toBe('MIA')
        ->and($model->verdict)->toBe('insufficient_data')
        /*
         * True now, and it is the mirror doing its job rather than a
         * slackened assertion: `allows_picks` is written from
         * Verdict::allowsRecommendation(), and a market with too small
         * a sample may state an opinion while claiming nothing. What it
         * still may not do is claim an edge.
         */
        ->and($model->allows_picks)->toBeTrue()
        ->and(Verdict::from($model->verdict)->claimsEdge())->toBeFalse();
});

it('re-reads every model rather than paging past one whose verdict moved', function () {
    $version = engineModelVersion();

    dispatch_sync(new SyncModels);

    // A keyset cursor would skip this id next time, and the product would
    // keep selling against a verdict that had since been withdrawn.
    expect(SyncRun::query()->feed('models')->latestFirst()->first()->cursor_after)->toBeNull();

    /*
     * Retrained, verdict withdrawn. A model version is the one engine
     * record that is genuinely updated in place - its metrics are
     * rewritten when it is retrained - which is exactly why this feed
     * cannot use a cursor.
     */
    $version->forceFill([
        'holdout_metrics' => ['verdict' => 'negative', 'verdict_reason' => 'Behind the closing line.'],
    ])->save();

    dispatch_sync(new SyncModels);

    // Two rows, not one overwritten: which verdict was in force when a
    // pick was published is part of the record.
    expect(VlModel::count())->toBe(2)
        ->and(VlModel::query()->current()->count())->toBe(1)
        ->and(VlModel::query()->current()->first()->verdict)->toBe('negative');
});

it('never updates a mirrored row in place', function () {
    engineBoardEntry();
    dispatch_sync(new SyncBoard);

    $entry = VlBoardEntry::firstOrFail();

    expect(fn () => $entry->update(['recommendation' => 'bet']))
        ->toThrow(RuntimeException::class, 'append-only');

    expect(fn () => $entry->delete())->toThrow(RuntimeException::class, 'append-only');
});

it('reads nothing at all when the engine is switched off', function () {
    config()->set('edgelab.enabled', false);

    engineBoardEntry();

    dispatch_sync(new SyncBoard);

    /*
     * A skip, recorded. The board being empty and the projection being
     * switched off look identical from the board itself and mean opposite
     * things to somebody about to place a bet, so the run says which.
     */
    expect(VlBoardEntry::count())->toBe(0)
        ->and(SyncRun::firstOrFail()->status)->toBe('skipped')
        ->and(SyncRun::firstOrFail()->message)->toContain('switched off');
});

/*
|--------------------------------------------------------------------------
| The seam between a game and its score
|--------------------------------------------------------------------------
|
| `events` is the one feed Vigil reads that is not append-only: a game is
| inserted when the schedule is published and then edited in place when it
| finishes, same row and same id. A keyset cursor can only ever offer that
| row once, on the day it was scheduled, with no score on it.
|
| That is not hypothetical. It ran in production: every mirrored game sat
| frozen at its pre-kickoff state, so the public record, a customer's bets
| and their parlays could never settle, while the suite stayed green
| because every settling test hand-built a VlEvent with the score already
| in it. These tests are the seam those tests skipped.
|
*/

it('re-reads a game whose final score landed after it was first mirrored', function () {
    $game = engineGame('Kansas City Chiefs', 'Buffalo Bills');

    dispatch_sync(new SyncEvents);

    expect(VlEvent::query()->current()->first()->final_home_score)->toBeNull();

    // Filled in by the results pull, which updates the row rather than
    // writing a new one. The id does not move, so a cursor never sees it.
    $game->forceFill([
        'status' => 'final',
        'final_home_score' => 27,
        'final_away_score' => 24,
        'completed_at' => now(),
    ])->save();

    dispatch_sync(new SyncEvents);

    $current = VlEvent::query()->current()->first();

    expect($current->final_home_score)->toBe(27)
        ->and($current->final_away_score)->toBe(24)
        ->and($current->status)->toBe('final')
        // Both readings kept. What the mirror believed before the result
        // arrived is still part of the history, as it is for every feed.
        ->and(VlEvent::where('edge_lab_id', $game->id)->count())->toBe(2);
});

it('writes nothing on a re-read of a game that has not changed', function () {
    engineGame();

    dispatch_sync(new SyncEvents);
    dispatch_sync(new SyncEvents);

    // The refresh pass walks the same games every hour. That is only
    // affordable because the digest means an unchanged row costs a read
    // and no write at all.
    expect(VlEvent::count())->toBe(1)
        ->and(SyncRun::query()->feed('events')->latestFirst()->first()->records_written)->toBe(0);
});

it('still advances the discovery cursor, so the whole schedule is not re-walked', function () {
    $game = engineGame();

    dispatch_sync(new SyncEvents);

    // The refresh window is bounded; discovery is not, and a feed that
    // forgot its cursor would re-read every game ever scheduled.
    expect(SyncRun::query()->feed('events')->latestFirst()->first()->cursor_after)->toBe($game->id);
});

it('leaves a game far outside the refresh window alone', function () {
    $game = engineGame(overrides: ['starts_at' => now()->subDays(60)]);

    dispatch_sync(new SyncEvents);

    $game->forceFill(['final_home_score' => 27, 'final_away_score' => 24])->save();

    dispatch_sync(new SyncEvents);

    // A result two months late is not worth re-reading every game ever
    // played to catch. The window is the bound that keeps this cheap.
    expect(VlEvent::where('edge_lab_id', $game->id)->count())->toBe(1);
});
