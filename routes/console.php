<?php

declare(strict_types=1);

use App\Jobs\EdgeLab\SyncBoard;
use App\Jobs\EdgeLab\SyncEvents;
use App\Jobs\EdgeLab\SyncModels;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
|
| Everything queues a job rather than working in the scheduler process, so
| a slow or dead Edge Lab cannot block the tick, and every run lands in
| sync_runs whether it succeeds or fails.
|
| The cadences follow what each feed upstream actually does. Pulling more
| often than the engine rescans spends Edge Lab's rate limit to re-read
| rows that have not changed, and the digest means those writes would
| produce nothing anyway.
|
*/

// Edge Lab rescans the board hourly at :45. This runs after it, not with
// it, so a sync is reading a finished scan rather than one in progress.
Schedule::job(new SyncBoard)
    ->hourlyAt(55)
    ->name('edgelab-sync-board')
    ->withoutOverlapping();

// The schedule barely moves. Twice a day is enough to catch a postponement.
Schedule::job(new SyncEvents)
    ->twiceDaily(7, 19)
    ->name('edgelab-sync-events')
    ->withoutOverlapping();

/*
 * Verdicts change only when a model is retrained, which is weekly. Pulled
 * daily anyway: this feed decides what Vigil is allowed to sell, and being
 * a day late withdrawing a verdict is worse than the cost of the request.
 */
Schedule::job(new SyncModels)
    ->dailyAt('05:30')
    ->name('edgelab-sync-models')
    ->withoutOverlapping();

/*
 * Dunning's deadline. Daily, early, before anybody is likely to be
 * looking: a customer should not watch a tier disappear mid-session.
 *
 * The retries themselves are the processor's; this only closes out the
 * ones whose window has run out, which has to be checked on a clock
 * because a processor that gives up quietly sends nothing to react to.
 */
Schedule::command('vigil:dunning')
    ->dailyAt('06:10')
    ->name('billing-dunning')
    ->withoutOverlapping();
