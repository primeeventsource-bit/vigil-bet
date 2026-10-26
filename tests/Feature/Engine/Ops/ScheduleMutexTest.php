<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

/*
 * A stranded overlap lock is a silent outage.
 *
 * `withoutOverlapping()` defaults to holding its mutex for 1440 minutes
 * - a full day. The lock is taken before a task runs and released after,
 * so a process killed in between leaves it set, and every later tick of
 * that one task is skipped. Skipped, not failed: nothing is dispatched,
 * no run is recorded, no job fails, and no screen says anything except
 * the stale-data banner hours later.
 *
 * That is how the board sync came to miss four hours in a row while the
 * scheduler was demonstrably alive and running eleven to fifty-six other
 * jobs an hour around it.
 *
 * The ceiling here is not about overlap protection, which every one of
 * these still has. It is about how long a mistake is allowed to last.
 */
it('gives every overlap guard an expiry it can recover from', function () {
    /*
     * Counted first, because both assertions below are satisfied by an
     * empty schedule. A guard that passes when it has nothing to look
     * at is the failure this repository spent a day finding once
     * already.
     */
    expect(app(Schedule::class)->events())->not->toBeEmpty();

    $offenders = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => $event->withoutOverlapping)
        ->filter(fn ($event) => $event->expiresAt > 60)
        ->map(fn ($event) => ($event->description ?? $event->command ?? 'unnamed').' holds for '.$event->expiresAt.' minutes')
        ->values()
        ->all();

    expect($offenders)->toBe([], implode('; ', $offenders)
        .'. A lock held this long turns one killed process into hours of'
        .' silence. Pass an expiry to withoutOverlapping().');
});

it('still guards every one of them against overlapping', function () {
    /*
     * The companion assertion, so that "fix the expiry" can never be
     * done by removing the guard. Everything on this schedule either
     * writes to an append-only table or spends a metered quota, and two
     * copies of either is the thing being prevented.
     */
    $unguarded = collect(app(Schedule::class)->events())
        ->reject(fn ($event) => $event->withoutOverlapping)
        ->map(fn ($event) => $event->description ?? $event->command ?? 'unnamed')
        ->values()
        ->all();

    expect($unguarded)->toBe([], 'Unguarded: '.implode(', ', $unguarded));
});
