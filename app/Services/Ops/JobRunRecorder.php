<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Enums\JobStatus;
use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Models\JobRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wraps a unit of work so that exactly one job_runs row is written whether it
 * succeeds or blows up. Nothing in this app is allowed to fail quietly.
 */
class JobRunRecorder
{
    public function start(
        string $jobKey,
        ?string $jobClass = null,
        Sport|string|null $sport = null,
        TriggerSource $triggeredBy = TriggerSource::Schedule,
        array $context = [],
        int $attempt = 1,
    ): JobRun {
        return JobRun::create([
            'job_key' => $jobKey,
            'job_class' => $jobClass,
            'sport' => $sport instanceof Sport ? $sport->value : $sport,
            'status' => JobStatus::Running,
            'triggered_by' => $triggeredBy,
            'queue' => config('queue.default'),
            'started_at' => now(),
            'attempt' => $attempt,
            'context' => $context ?: null,
            'created_at' => now(),
        ]);
    }

    public function succeed(JobRun $run, int $recordsIn = 0, int $recordsOut = 0, array $context = []): JobRun
    {
        $run->forceFill([
            'status' => JobStatus::Success,
            'finished_at' => now(),
            'duration_ms' => $this->elapsedMs($run),
            'records_in' => $recordsIn,
            'records_out' => $recordsOut,
            'context' => array_merge((array) $run->context, $context) ?: null,
        ])->save();

        return $run;
    }

    public function skip(JobRun $run, string $why, array $context = []): JobRun
    {
        $run->forceFill([
            'status' => JobStatus::Skipped,
            'finished_at' => now(),
            'duration_ms' => $this->elapsedMs($run),
            'error_message' => $why,
            'context' => array_merge((array) $run->context, $context) ?: null,
        ])->save();

        return $run;
    }

    public function fail(JobRun $run, Throwable $e, array $context = []): JobRun
    {
        $run->forceFill([
            'status' => JobStatus::Failed,
            'finished_at' => now(),
            'duration_ms' => $this->elapsedMs($run),
            'error_class' => $e::class,
            'error_message' => mb_substr($e->getMessage(), 0, 2000),
            'context' => array_merge((array) $run->context, $context, [
                'file' => $e->getFile().':'.$e->getLine(),
            ]) ?: null,
        ])->save();

        Log::error('Edge Lab job failed: '.$run->job_key, [
            'job_run_id' => $run->id,
            'exception' => $e,
        ]);

        return $run;
    }

    /**
     * Run a closure inside a recorded job. Re-throws after recording so the
     * queue can retry, but the failure is already on the Jobs screen.
     *
     * @template T
     *
     * @param  callable(JobRun): T  $work
     * @return T
     */
    public function record(string $jobKey, callable $work, Sport|string|null $sport = null, array $context = [], TriggerSource $triggeredBy = TriggerSource::Schedule): mixed
    {
        $run = $this->start($jobKey, null, $sport, $triggeredBy, $context);

        try {
            $result = $work($run);

            if ($run->status === JobStatus::Running) {
                $this->succeed($run);
            }

            return $result;
        } catch (Throwable $e) {
            $this->fail($run, $e);

            throw $e;
        }
    }

    /**
     * Close out a run whose worker never came back.
     *
     * Recorded as failed, because a run that produced no result did not
     * succeed and the Jobs screen must not go on counting it as live. The
     * reason says it was reaped rather than observed to fail: "nothing came
     * back" and "it threw" are different facts about a job, and flattening
     * them would cost the only clue that an instance died mid-run.
     *
     * finished_at is the moment this was noticed, not the moment the worker
     * stopped, which is unknowable. duration_ms is therefore an upper
     * bound, not a measurement.
     */
    public function abandon(JobRun $run, int $afterMinutes): JobRun
    {
        $run->forceFill([
            'status' => JobStatus::Failed,
            'finished_at' => now(),
            'duration_ms' => $this->elapsedMs($run),
            'error_class' => 'AbandonedRun',
            'error_message' => sprintf(
                'Still marked running %d minutes after it started, so the worker is '
                .'assumed gone. Closed by the reaper; this job was never seen to fail.',
                $afterMinutes,
            ),
        ])->save();

        Log::warning('Edge Lab job abandoned: '.$run->job_key, [
            'job_run_id' => $run->id,
            'started_at' => $run->started_at?->toIso8601String(),
        ]);

        return $run;
    }

    private function elapsedMs(JobRun $run): int
    {
        return (int) max(0, now()->diffInMilliseconds($run->started_at, true));
    }
}
