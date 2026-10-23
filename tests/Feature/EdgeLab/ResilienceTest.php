<?php

declare(strict_types=1);

use App\Jobs\EdgeLab\SyncBoard;
use App\Models\SyncRun;
use App\Models\VlBoardEntry;
use App\Services\EdgeLab\CircuitBreaker;
use App\Services\EdgeLab\EdgeLabClient;
use App\Services\EdgeLab\EdgeLabException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('edgelab.enabled', true);
    config()->set('edgelab.token', 'a-shared-secret');
    config()->set('edgelab.base_url', 'https://parlayq.test/internal/v1');
    config()->set('edgelab.http.retries', 1);
    app(CircuitBreaker::class)->reset();
});

it('records a failure rather than failing silently', function () {
    Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

    dispatch_sync(new SyncBoard);

    $run = SyncRun::firstOrFail();

    expect($run->status)->toBe('failed')
        ->and($run->message)->toContain('401')
        ->and(VlBoardEntry::count())->toBe(0);
});

it('does not advance the cursor past rows it never read', function () {
    Http::fake(['*/board*' => cursorAwareFake([boardRow(1), boardRow(2)])]);
    dispatch_sync(new SyncBoard);

    $cursor = SyncRun::cursorFor('board');
    expect($cursor)->toBe(2);

    Http::fake(['*' => Http::response([], 500)]);
    dispatch_sync(new SyncBoard);

    // The failed run must leave the cursor where the last good one put
    // it. These feeds are append-only; a row stepped over is a row Vigil
    // never sees again.
    expect(SyncRun::cursorFor('board'))->toBe(2);
});

it('does not retry a disagreement about the request', function () {
    config()->set('edgelab.http.retries', 3);

    Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

    dispatch_sync(new SyncBoard);

    // A 401 says the same thing however many times it is asked, and
    // retrying spends the per-minute allowance the sync needs once the
    // token is fixed.
    Http::assertSentCount(1);
});

it('opens the breaker after repeated failures and stops calling out', function () {
    config()->set('edgelab.breaker.failure_threshold', 3);

    Http::fake(['*' => Http::response([], 500)]);

    foreach (range(1, 3) as $ignored) {
        dispatch_sync(new SyncBoard);
    }

    expect(app(CircuitBreaker::class)->state())->toBe('open');

    $callsBefore = count(Http::recorded());

    dispatch_sync(new SyncBoard);

    // Failing fast, without leaving the building. One outage must not
    // become two by burning Edge Lab's rate limit on failures.
    expect(count(Http::recorded()))->toBe($callsBefore)
        ->and(SyncRun::query()->latestFirst()->first()->status)->toBe('skipped');
});

it('lets one call through once the cooldown has passed', function () {
    config()->set('edgelab.breaker.failure_threshold', 2);
    config()->set('edgelab.breaker.cooldown_seconds', 60);

    Http::fake(['*' => Http::response([], 500)]);
    dispatch_sync(new SyncBoard);
    dispatch_sync(new SyncBoard);

    expect(app(CircuitBreaker::class)->state())->toBe('open');

    $this->travel(61)->seconds();

    expect(app(CircuitBreaker::class)->state())->toBe('half-open')
        ->and(app(CircuitBreaker::class)->allowsRequest())->toBeTrue();
});

it('closes the breaker again on a success', function () {
    config()->set('edgelab.breaker.failure_threshold', 5);

    /*
     * One fake that fails and then recovers. Registering a second
     * Http::fake would not replace this one - the stubs merge and the
     * first match wins - so the "recovered" stub would never be reached
     * and the test would pass or fail for the wrong reason.
     */
    $healthy = false;
    Http::fake(['*/board*' => function ($request) use (&$healthy) {
        return $healthy
            ? cursorAwareFake([boardRow(1)])($request)
            : Http::response([], 500);
    }]);

    dispatch_sync(new SyncBoard);

    expect(app(CircuitBreaker::class)->consecutiveFailures())->toBe(1);

    $healthy = true;
    dispatch_sync(new SyncBoard);

    // A single success resets the count. An upstream that answers one
    // call in three is unwell but reachable, and retries are the right
    // response to that rather than an open breaker.
    expect(app(CircuitBreaker::class)->state())->toBe('closed')
        ->and(app(CircuitBreaker::class)->consecutiveFailures())->toBe(0)
        ->and(VlBoardEntry::count())->toBe(1);
});

it('refuses to call out at all without a token', function () {
    config()->set('edgelab.enabled', false);
    config()->set('edgelab.token', null);

    Http::fake();

    dispatch_sync(new SyncBoard);

    Http::assertNothingSent();

    expect(SyncRun::firstOrFail()->status)->toBe('skipped')
        ->and(SyncRun::firstOrFail()->message)->toContain('EDGE_LAB_TOKEN');

    expect(fn () => app(EdgeLabClient::class)->board())->toThrow(EdgeLabException::class);
});
