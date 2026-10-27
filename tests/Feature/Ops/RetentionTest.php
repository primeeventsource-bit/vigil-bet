<?php

declare(strict_types=1);

use App\Models\ApiRequest;
use App\Models\Concerns\AppendOnly;
use App\Models\JobRun;
use App\Models\SyncRun;
use App\Services\Ops\ApiMeter;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Ceilings on the operational tables
|--------------------------------------------------------------------------
|
| Three tables hold telemetry about the run rather than a claim about the
| world, and nothing was deleting any of them. `job_runs` was taking
| 3,700 rows a day in production for a table whose readers only ever want
| the newest row per job key.
|
| What these tests defend is not the deleting. It is the two things the
| deleting could quietly break: the cursor a feed resumes from, and the
| month ApiMeter sums to decide whether there is budget left to pull odds
| at all. Both are read off a single row or a single window, and a prune
| that took either would leave every table looking exactly as it should.
|
*/

function jobRunAt(string $at, string $key = 'odds:pull:nfl'): JobRun
{
    return JobRun::create([
        'job_key' => $key,
        'status' => 'success',
        'started_at' => $at,
        'created_at' => $at,
    ]);
}

/* ----------------------------------------------------------------------
 | job_runs
 ---------------------------------------------------------------------- */

it('prunes job runs past the window and keeps the ones inside it', function () {
    $old = jobRunAt(now()->subDays(45)->toDateTimeString());
    $kept = jobRunAt(now()->subDays(5)->toDateTimeString());

    $prunable = (new JobRun)->prunable()->pluck('id')->all();

    expect($prunable)->toBe([$old->id])
        ->and($prunable)->not->toContain($kept->id);
});

it('actually deletes them when the scheduled command runs', function () {
    jobRunAt(now()->subDays(45)->toDateTimeString());
    $kept = jobRunAt(now()->subHour()->toDateTimeString());

    $this->artisan('model:prune')->assertExitCode(0);

    expect(JobRun::pluck('id')->all())->toBe([$kept->id]);
});

it('makes no exception for the newest run of a job key', function () {
    /*
     * Deliberate, and the opposite of SyncRun below. The worst a missing
     * last-run time can do is leave a cadence counting the job as due,
     * which costs one early pull and writes a fresh row - it heals on
     * the next tick. SyncRun's newest row carries a cursor, which does
     * not heal.
     */
    jobRunAt(now()->subDays(45)->toDateTimeString(), 'odds:pull:nfl');

    $this->artisan('model:prune')->assertExitCode(0);

    expect(JobRun::where('job_key', 'odds:pull:nfl')->exists())->toBeFalse();
});

/* ----------------------------------------------------------------------
 | api_requests - the window the budget guard reads
 ---------------------------------------------------------------------- */

it('keeps api requests for longer than the longest calendar month', function () {
    /*
     * The assertion that matters most in this file.
     *
     * ApiMeter::creditsThisMonth sums from startOfMonth, so on 31 March
     * it is asking for 31 days of history. A window shorter than that
     * would understate spend - and an understated spend is a guard that
     * waves through the request which blows the monthly quota, with
     * nothing on any screen to say why.
     */
    expect((int) config('edge.retention.api_requests_days'))->toBeGreaterThan(31);
});

it('still sums a full month of credits after a prune', function () {
    $meter = app(ApiMeter::class);

    // Inside this calendar month, whatever day of it today happens to be.
    ApiRequest::create([
        'provider' => 'odds_api',
        'endpoint' => '/sports',
        'credits_cost' => 10,
        'requested_at' => now()->startOfMonth(),
    ]);

    ApiRequest::create([
        'provider' => 'odds_api',
        'endpoint' => '/sports',
        'credits_cost' => 999,
        'requested_at' => now()->subDays(400),
    ]);

    $before = $meter->creditsThisMonth('odds_api');

    $this->artisan('model:prune')->assertExitCode(0);

    expect($meter->creditsThisMonth('odds_api'))->toBe($before)
        ->and($before)->toBe(10);
});

/* ----------------------------------------------------------------------
 | sync_runs - the cursor
 ---------------------------------------------------------------------- */

it('never prunes the newest successful run of a feed, however old it is', function () {
    $cursorRow = SyncRun::create([
        'feed' => 'events',
        'status' => 'success',
        'cursor_after' => 90210,
        'started_at' => now()->subDays(400),
    ]);

    $older = SyncRun::create([
        'feed' => 'events',
        'status' => 'success',
        'cursor_after' => 11,
        'started_at' => now()->subDays(500),
    ]);

    $failure = SyncRun::create([
        'feed' => 'events',
        'status' => 'failed',
        'started_at' => now()->subDays(401),
    ]);

    $prunable = (new SyncRun)->prunable()->pluck('id')->all();

    expect($prunable)->not->toContain($cursorRow->id)
        ->and($prunable)->toContain($older->id)
        ->and($prunable)->toContain($failure->id);
});

it('can still answer where a feed resumes from after a prune', function () {
    SyncRun::create([
        'feed' => 'events',
        'status' => 'success',
        'cursor_after' => 11,
        'started_at' => now()->subDays(500),
    ]);

    SyncRun::create([
        'feed' => 'events',
        'status' => 'success',
        'cursor_after' => 90210,
        'started_at' => now()->subDays(400),
    ]);

    $this->artisan('model:prune')->assertExitCode(0);

    // The whole feed is past the window; the cursor survives anyway,
    // because losing it sends SyncEvents back to walk every event again
    // to re-derive what it already knew - paid in Edge Lab requests,
    // with every table looking exactly as it should.
    expect(SyncRun::cursorFor('events'))->toBe(90210);
});

it('keeps one cursor per feed rather than one overall', function () {
    foreach (['events', 'board', 'odds'] as $index => $feed) {
        SyncRun::create([
            'feed' => $feed,
            'status' => 'success',
            'cursor_after' => 100 + $index,
            'started_at' => now()->subDays(400),
        ]);
    }

    $this->artisan('model:prune')->assertExitCode(0);

    expect(SyncRun::cursorFor('events'))->toBe(100)
        ->and(SyncRun::cursorFor('board'))->toBe(101)
        ->and(SyncRun::cursorFor('odds'))->toBe(102);
});

/* ----------------------------------------------------------------------
 | Nothing that holds a claim is prunable
 ---------------------------------------------------------------------- */

it('prunes no table that holds a claim', function () {
    /*
     * Derived from the models, like the append-only sweep, because the
     * failure this guards against is somebody adding MassPrunable to a
     * model that records something - a snapshot, a pick, a result, an
     * audit row - where the whole value is that it cannot be rewritten.
     */
    $prunable = [];

    foreach (File::files(app_path('Models')) as $file) {
        $class = 'App'.chr(92).'Models'.chr(92).$file->getFilenameWithoutExtension();

        if (! class_exists($class)) {
            continue;
        }

        $traits = class_uses_recursive($class);

        if (! in_array(MassPrunable::class, $traits, true)) {
            continue;
        }

        expect(in_array(AppendOnly::class, $traits, true))->toBeFalse(
            class_basename($class).' is append-only and must not be prunable.',
        );

        $prunable[] = class_basename($class);
    }

    sort($prunable);

    expect($prunable)->toBe(['ApiRequest', 'JobRun', 'SyncRun']);
});
