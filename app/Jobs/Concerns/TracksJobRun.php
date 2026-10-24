<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Models\JobRun;
use App\Services\Ops\JobRunRecorder;
use Throwable;

/**
 * Every queued job in this app records a job_runs row through this trait, so
 * the Jobs screen shows what ran, what it wrote, and what broke. A job that
 * fails loudly is the point; a job that fails quietly is a bug.
 */
trait TracksJobRun
{
    public ?int $jobRunId = null;

    protected ?JobRun $jobRun = null;

    /**
     * @template T
     *
     * @param  callable(JobRun): T  $work
     * @return T
     */
    protected function tracked(string $jobKey, callable $work, Sport|string|null $sport = null, array $context = []): mixed
    {
        $recorder = app(JobRunRecorder::class);

        $this->jobRun = $recorder->start(
            jobKey: $jobKey,
            jobClass: static::class,
            sport: $sport,
            triggeredBy: $this->triggerSource(),
            context: $context,
            attempt: method_exists($this, 'attempts') ? max(1, $this->attempts()) : 1,
        );

        $this->jobRunId = $this->jobRun->id;

        try {
            $result = $work($this->jobRun);

            if ($this->jobRun->status->value === 'running') {
                $recorder->succeed(
                    $this->jobRun,
                    is_array($result) ? (int) ($result['records_in'] ?? 0) : 0,
                    is_array($result) ? (int) ($result['records_out'] ?? 0) : 0,
                    is_array($result) ? $result : [],
                );
            }

            return $result;
        } catch (Throwable $e) {
            $recorder->fail($this->jobRun, $e);

            throw $e;
        }
    }

    protected function triggerSource(): TriggerSource
    {
        return property_exists($this, 'triggeredBy') && $this->triggeredBy instanceof TriggerSource
            ? $this->triggeredBy
            : TriggerSource::Schedule;
    }
}
