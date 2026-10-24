<?php

declare(strict_types=1);

use App\Jobs\EdgeLab\SyncBoard;
use App\Models\SyncRun;
use App\Models\ValueBet;
use App\Models\ValueScan;
use App\Models\VlBoardEntry;
use App\Services\EdgeLab\EdgeLabClient;
use App\Services\EdgeLab\EdgeLabException;

/*
|--------------------------------------------------------------------------
| What can still go wrong
|--------------------------------------------------------------------------
|
| This file used to be twice this size. Most of it covered a circuit
| breaker opening after repeated failures, a cooldown letting one call
| through, and a 401 not being retried - all of which existed because the
| engine was reached over HTTP and had a per-minute rate limit. There is
| no network left to be unwell, so those tests have gone with the code
| they described rather than being kept as theatre.
|
| What remains is the part that was never about transport: a failure has
| to be recorded rather than swallowed, and a run that failed must not
| move the cursor past rows it never read. Both still matter, and the
| second matters more than it reads - the feeds are append-only, so a row
| stepped over is a row the product never sees again.
|
| One test is new, and only possible now: the projection reads the engine
| and must not write to it.
|
*/

beforeEach(function () {
    config()->set('edgelab.enabled', true);
});

it('records a failure rather than failing silently', function () {
    $this->partialMock(EdgeLabClient::class, function ($client) {
        $client->shouldReceive('board')->andThrow(new EdgeLabException('The engine refused the query.'));
    });

    dispatch_sync(new SyncBoard);

    $run = SyncRun::firstOrFail();

    expect($run->status)->toBe('failed')
        ->and($run->message)->toContain('refused the query')
        ->and($run->finished_at)->not->toBeNull()
        ->and(VlBoardEntry::count())->toBe(0);
});

it('does not advance the cursor past rows it never read', function () {
    $scan = engineScan();
    engineBoardEntry($scan);
    $second = engineBoardEntry($scan);

    dispatch_sync(new SyncBoard);

    expect(SyncRun::cursorFor('board'))->toBe($second->id);

    // A third row lands, and then the next run cannot read it.
    engineBoardEntry($scan);

    $this->partialMock(EdgeLabClient::class, function ($client) {
        $client->shouldReceive('board')->andThrow(new EdgeLabException('Gone away.'));
    });

    dispatch_sync(new SyncBoard);

    /*
     * The failed run leaves the cursor where the last good one put it, so
     * the third row is read by the next tick rather than skipped forever.
     */
    expect(SyncRun::cursorFor('board'))->toBe($second->id)
        ->and(SyncRun::query()->latestFirst()->first()->status)->toBe('failed')
        ->and(VlBoardEntry::count())->toBe(2);
});

it('refuses to read at all when the engine is switched off', function () {
    config()->set('edgelab.enabled', false);

    dispatch_sync(new SyncBoard);

    expect(SyncRun::firstOrFail()->status)->toBe('skipped')
        ->and(SyncRun::firstOrFail()->message)->toContain('switched off');

    expect(fn () => app(EdgeLabClient::class)->evaluateParlay([
        ['event_id' => 1, 'market' => 'h2h', 'side' => 'home'],
    ]))->toThrow(EdgeLabException::class);
});

it('reads the engine without writing to it', function () {
    /*
     * Only possible to assert now that both halves share a database, and
     * worth asserting precisely because of that. The read-only rule used
     * to be enforced by the shape of an HTTP API with no write routes;
     * with the tables directly in reach, nothing stops a future edit here
     * from updating one except a test that notices.
     */
    $scan = engineScan();
    engineBoardEntry($scan);
    engineBoardEntry($scan);
    engineModelVersion();

    $before = [
        'scans' => ValueScan::count(),
        'bets' => ValueBet::count(),
        'flagged' => ValueBet::query()->orderBy('id')->pluck('flagged_at')->map->toIso8601String()->all(),
    ];

    dispatch_sync(new SyncBoard);

    expect(VlBoardEntry::count())->toBe(2)
        ->and(ValueScan::count())->toBe($before['scans'])
        ->and(ValueBet::count())->toBe($before['bets'])
        ->and(ValueBet::query()->orderBy('id')->pluck('flagged_at')->map->toIso8601String()->all())
        ->toBe($before['flagged']);
});
