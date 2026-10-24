<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\JobRun;
use App\Services\Ingestion\EventSynchronizer;
use App\Services\Ingestion\SourceRegistry;
use App\Services\Ops\JobRunRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Pulls upcoming games for a sport into events, so odds have something to
 * attach to and the Today screen has a board to render.
 */
class SyncSchedules implements ShouldQueue
{
    use Queueable, TracksJobRun;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public readonly Sport $sport,
        public readonly int $daysAhead = 10,
        public readonly TriggerSource $triggeredBy = TriggerSource::Schedule,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('schedules:'.$this->sport->value))->expireAfter(900)];
    }

    public function handle(SourceRegistry $registry, EventSynchronizer $sync, JobRunRecorder $recorder): void
    {
        $this->tracked(
            "ingest:schedules:{$this->sport->value}",
            function (JobRun $run) use ($registry, $sync, $recorder): array {
                $source = $registry->forRole($this->sport, 'schedules');

                if ($source === null) {
                    $recorder->skip($run, "No schedule source configured for {$this->sport->value}.");

                    return [];
                }

                if (! $source->isAvailable()) {
                    $recorder->skip($run, "Source [{$source->key()}] is unavailable, most likely a missing API key.");

                    return [];
                }

                $source->withJobRun($run->id);

                $from = CarbonImmutable::now()->subDay();
                $to = CarbonImmutable::now()->addDays($this->daysAhead);

                $stats = $sync->sync($source->games($from, $to), $source->key());

                return [
                    'records_in' => $stats['seen'],
                    'records_out' => $stats['created'] + $stats['updated'],
                    'created' => $stats['created'],
                    'updated' => $stats['updated'],
                    'unresolved_teams' => $stats['unresolved'],
                    'source' => $source->key(),
                    'reliability' => $source->reliability()->value,
                ];
            },
            $this->sport,
        );
    }
}
