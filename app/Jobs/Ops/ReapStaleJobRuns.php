<?php

declare(strict_types=1);

namespace App\Jobs\Ops;

use App\Enums\JobStatus;
use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\JobRun;
use App\Services\Ops\JobRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Closes out job runs whose worker never came back.
 *
 * A run is marked running when it starts, and the same process marks it
 * finished. When that process dies - the instance hibernates, the
 * container is replaced mid-deploy, the worker is killed - nobody writes
 * the second half and the row says "running" for good.
 *
 * That is worse here than it sounds. The Jobs screen exists so that a
 * silent failure is impossible, and a permanently-running row is a silent
 * failure wearing the one costume that screen cannot see through: it looks
 * like work in progress. Production carried one for twenty-one hours after
 * an instance went down mid-sync, and the page reported it as live the
 * whole time.
 *
 * Nothing is deleted and nothing is retried. Re-running the work is the
 * scheduler's job and it will come round again on its own cadence; all
 * this does is stop the record lying about what is happening now.
 */
class ReapStaleJobRuns implements ShouldQueue
{
    use Queueable, TracksJobRun;

    /**
     * No retry. A failed reap costs one stale row another fifteen minutes,
     * which is not worth a second attempt against a database that just
     * refused the first.
     */
    public int $tries = 1;

    public int $timeout = 120;

    /**
     * Long enough that no healthy job is still working.
     *
     * The slowest jobs here cap themselves at a 600-second timeout, so
     * thirty minutes is well past anything that is merely slow. Being
     * eager would be the worse error by far: closing a run that is still
     * going reports a failure that never happened, and the Jobs screen is
     * only worth reading while everything on it is true.
     */
    public const STALE_AFTER_MINUTES = 30;

    public function __construct(
        public readonly TriggerSource $triggeredBy = TriggerSource::Schedule,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('ops:reap'))->expireAfter(300)];
    }

    public function handle(JobRunRecorder $recorder): void
    {
        $this->tracked('ops:reap', function (JobRun $run) use ($recorder): array {
            $stale = JobRun::query()
                ->where('status', JobStatus::Running)
                ->where('started_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
                // This run is itself marked running while it does the work.
                ->whereKeyNot($run->id)
                ->orderBy('id')
                ->get();

            foreach ($stale as $abandoned) {
                $recorder->abandon($abandoned, self::STALE_AFTER_MINUTES);
            }

            return [
                'records_in' => $stale->count(),
                'records_out' => $stale->count(),
                'reaped' => $stale
                    ->map(fn (JobRun $r) => $r->job_key.($r->sport ? ':'.$r->sport->value : ''))
                    ->all(),
            ];
        });
    }
}
