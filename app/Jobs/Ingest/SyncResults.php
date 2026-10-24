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
 * Brings finished games up to date. Grading and CLV both wait on this, so it
 * runs often enough that a settled bet does not sit pending overnight.
 */
class SyncResults implements ShouldQueue
{
    use Queueable, TracksJobRun;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public readonly Sport $sport,
        public readonly int $lookbackDays = 3,
        public readonly TriggerSource $triggeredBy = TriggerSource::Schedule,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('results:'.$this->sport->value))->expireAfter(900)];
    }

    public function handle(SourceRegistry $registry, EventSynchronizer $sync, JobRunRecorder $recorder): void
    {
        $this->tracked(
            "ingest:results:{$this->sport->value}",
            function (JobRun $run) use ($registry, $sync, $recorder): array {
                $source = $registry->forRole($this->sport, 'results');

                if ($source === null || ! $source->isAvailable()) {
                    $recorder->skip($run, 'No available results source for '.$this->sport->value.'.');

                    return [];
                }

                $source->withJobRun($run->id);

                $stats = $sync->sync(
                    $source->games(
                        CarbonImmutable::now()->subDays($this->lookbackDays),
                        CarbonImmutable::now(),
                    ),
                    $source->key(),
                );

                return [
                    'records_in' => $stats['seen'],
                    'records_out' => $stats['updated'] + $stats['created'],
                    'unresolved_teams' => $stats['unresolved'],
                    'source' => $source->key(),
                ];
            },
            $this->sport,
        );
    }
}
