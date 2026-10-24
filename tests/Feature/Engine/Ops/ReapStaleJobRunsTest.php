<?php

declare(strict_types=1);

use App\Enums\JobStatus;
use App\Enums\TriggerSource;
use App\Jobs\Ops\ReapStaleJobRuns;
use App\Models\JobRun;
use App\Services\Ops\JobRunRecorder;

function runningSince(string $key, int $minutesAgo, ?string $sport = null): JobRun
{
    return JobRun::create([
        'job_key' => $key,
        'job_class' => 'App\Jobs\Test',
        'sport' => $sport,
        'status' => JobStatus::Running->value,
        'triggered_by' => TriggerSource::Schedule,
        'queue' => 'default',
        'started_at' => now()->subMinutes($minutesAgo),
        'attempt' => 1,
        'created_at' => now()->subMinutes($minutesAgo),
    ]);
}

it('closes a run whose worker never came back', function () {
    $abandoned = runningSince('ingest:results:nfl', 90, 'nfl');

    (new ReapStaleJobRuns)->handle(app(JobRunRecorder::class));

    $abandoned->refresh();

    expect($abandoned->status)->toBe(JobStatus::Failed)
        ->and($abandoned->error_class)->toBe('AbandonedRun')
        ->and($abandoned->finished_at)->not->toBeNull()
        // The reason has to say this was reaped, not observed to fail. An
        // instance that died mid-run and a job that threw are different
        // problems and the log is the only place that distinction lives.
        ->and($abandoned->error_message)->toContain('assumed gone');
});

it('leaves a job that is merely slow alone', function () {
    // Closing a live run reports a failure that did not happen, which is
    // the worse error of the two: the Jobs screen is only worth reading
    // while everything on it is true.
    $working = runningSince('odds:pull:nfl', ReapStaleJobRuns::STALE_AFTER_MINUTES - 1, 'nfl');

    (new ReapStaleJobRuns)->handle(app(JobRunRecorder::class));

    expect($working->refresh()->status)->toBe(JobStatus::Running);
});

it('does not reap itself', function () {
    // The reaper is marked running while it works, so a query for stale
    // running rows can reach its own row the moment it is slow.
    $this->travelTo(now());

    (new ReapStaleJobRuns)->handle(app(JobRunRecorder::class));

    $own = JobRun::query()->where('job_key', 'ops:reap')->sole();

    expect($own->status)->toBe(JobStatus::Success);
});

it('leaves finished runs untouched', function () {
    $done = JobRun::create([
        'job_key' => 'bets:settle',
        'status' => JobStatus::Success->value,
        'triggered_by' => TriggerSource::Schedule,
        'started_at' => now()->subDays(3),
        'finished_at' => now()->subDays(3)->addSeconds(4),
        'attempt' => 1,
        'created_at' => now()->subDays(3),
    ]);

    (new ReapStaleJobRuns)->handle(app(JobRunRecorder::class));

    expect($done->refresh()->status)->toBe(JobStatus::Success)
        ->and($done->error_class)->toBeNull();
});

it('records what it reaped', function () {
    runningSince('ingest:results:nfl', 90, 'nfl');
    runningSince('ingest:weather', 120);

    (new ReapStaleJobRuns)->handle(app(JobRunRecorder::class));

    $own = JobRun::query()->where('job_key', 'ops:reap')->sole();

    expect($own->records_out)->toBe(2)
        ->and($own->context['reaped'])->toBe(['ingest:results:nfl:nfl', 'ingest:weather']);
});

it('writes a row even when there is nothing to reap', function () {
    // A pass that found nothing is a fact worth recording; a job that
    // sometimes leaves no row is indistinguishable from one that stopped.
    (new ReapStaleJobRuns)->handle(app(JobRunRecorder::class));

    expect(JobRun::query()->where('job_key', 'ops:reap')->sole()->records_out)->toBe(0);
});
