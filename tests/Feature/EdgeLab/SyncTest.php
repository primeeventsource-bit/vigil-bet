<?php

declare(strict_types=1);

use App\Jobs\EdgeLab\SyncBoard;
use App\Jobs\EdgeLab\SyncEvents;
use App\Jobs\EdgeLab\SyncModels;
use App\Models\SyncRun;
use App\Models\VlBoardEntry;
use App\Models\VlEvent;
use App\Models\VlModel;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('edgelab.enabled', true);
    config()->set('edgelab.token', 'a-shared-secret');
    config()->set('edgelab.base_url', 'https://parlayq.test/internal/v1');
});

it('mirrors the board exactly as Edge Lab judged it', function () {
    Http::fake(['*/board*' => Http::response(edgeLabPage([boardRow(1)], nextSince: null))]);

    dispatch_sync(new SyncBoard);

    $entry = VlBoardEntry::firstOrFail();

    // Every number is the engine's. Nothing is recomputed on this side.
    expect($entry->edge_lab_id)->toBe(1)
        ->and($entry->model_prob)->toBe(0.58)
        ->and($entry->fair_prob)->toBe(0.56)
        ->and($entry->edge_at_price)->toBe(-0.003)
        ->and($entry->recommendation)->toBe('pass')
        ->and($entry->pass_reason)->toBe('market_unproven')
        ->and($entry->market_verdict)->toBe('insufficient_data')
        ->and($entry->best_price_american)->toBe(-140);
});

it('keeps the passes, which are most of the board', function () {
    Http::fake(['*/board*' => Http::response(edgeLabPage([
        boardRow(1),
        boardRow(2, ['pass_reason' => 'no_price']),
    ]))]);

    dispatch_sync(new SyncBoard);

    expect(VlBoardEntry::count())->toBe(2)
        ->and(VlBoardEntry::query()->passes()->count())->toBe(2);
});

it('records a run for every sync, with what it fetched and what was new', function () {
    Http::fake(['*/board*' => cursorAwareFake([boardRow(1), boardRow(2)])]);

    dispatch_sync(new SyncBoard);

    $run = SyncRun::firstOrFail();

    expect($run->feed)->toBe('board')
        ->and($run->status)->toBe('success')
        ->and($run->records_fetched)->toBe(2)
        ->and($run->records_written)->toBe(2)
        ->and($run->cursor_after)->toBe(2)
        ->and($run->finished_at)->not->toBeNull();
});

it('resumes from where the last successful run finished', function () {
    /*
     * Edge Lab appends to the board; it never rewrites a judged
     * candidate. So a second scan arrives as new ids, and the cursor is
     * what stops Vigil re-reading the whole history every hour.
     *
     * One fake, registered once, holding a list that grows between the
     * two syncs. Calling Http::fake again would not replace this stub -
     * the stubs merge and the first match wins - so a second
     * registration would silently keep answering with the first.
     */
    $rows = [boardRow(1)];
    Http::fake(['*/board*' => function ($request) use (&$rows) {
        return cursorAwareFake($rows)($request);
    }]);

    dispatch_sync(new SyncBoard);

    $rows[] = boardRow(2);

    dispatch_sync(new SyncBoard);

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

        return ($q['since'] ?? null) === '1';
    });

    expect(VlBoardEntry::count())->toBe(2)
        ->and(SyncRun::query()->latestFirst()->first()->records_fetched)->toBe(1);
});

it('writes nothing when a quiet tick re-reads what it already has', function () {
    // The models feed deliberately re-reads from the start every time, so
    // it is the one that would append a duplicate every night without the
    // digest to stop it.
    Http::fake(['*/models*' => Http::response(edgeLabPage([modelRow(7)]))]);

    dispatch_sync(new SyncModels);
    expect(VlModel::count())->toBe(1);

    dispatch_sync(new SyncModels);

    expect(VlModel::count())->toBe(1)
        ->and(SyncRun::query()->latestFirst()->first()->records_written)->toBe(0);
});

it('mirrors the schedule and the models', function () {
    Http::fake([
        '*/events*' => Http::response(edgeLabPage([eventRow(100)])),
        '*/models*' => Http::response(edgeLabPage([modelRow(7)])),
    ]);

    dispatch_sync(new SyncEvents);
    dispatch_sync(new SyncModels);

    expect(VlEvent::firstOrFail()->home_name)->toBe('Buffalo Bills')
        ->and(VlEvent::firstOrFail()->away_abbreviation)->toBe('MIA')
        ->and(VlModel::firstOrFail()->verdict)->toBe('insufficient_data')
        ->and(VlModel::firstOrFail()->allows_picks)->toBeFalse();
});

it('re-reads every model rather than paging past one whose verdict moved', function () {
    $rows = [modelRow(7)];
    Http::fake(['*/models*' => function ($request) use (&$rows) {
        return cursorAwareFake($rows)($request);
    }]);

    dispatch_sync(new SyncModels);

    // A keyset cursor would skip id 7 next time, and Vigil would keep
    // selling against a verdict that had since been withdrawn.
    expect(SyncRun::query()->feed('models')->latestFirst()->first()->cursor_after)->toBeNull();

    // Same id, retrained, verdict withdrawn.
    $rows = [modelRow(7, 'negative')];

    dispatch_sync(new SyncModels);

    // Two rows, not one overwritten: which verdict was in force when a
    // pick was published is part of the record.
    expect(VlModel::count())->toBe(2)
        ->and(VlModel::query()->current()->count())->toBe(1)
        ->and(VlModel::query()->current()->first()->verdict)->toBe('negative');
});

it('never updates a mirrored row in place', function () {
    Http::fake(['*/board*' => Http::response(edgeLabPage([boardRow(1)]))]);
    dispatch_sync(new SyncBoard);

    $entry = VlBoardEntry::firstOrFail();

    expect(fn () => $entry->update(['recommendation' => 'bet']))
        ->toThrow(RuntimeException::class, 'append-only');

    expect(fn () => $entry->delete())->toThrow(RuntimeException::class, 'append-only');
});
