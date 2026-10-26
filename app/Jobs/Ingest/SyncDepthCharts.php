<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\JobRun;
use App\Services\Ingestion\EspnDepthChartSource;
use App\Services\Ops\JobRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Reads each team's depth chart.
 *
 * One request per team plus one for the league, which is why this is a
 * whole-league pass rather than a per-game one. Nothing here is urgent:
 * a chart moves when a coach says so, and the absence weighting it feeds
 * only needs to know first choice from fourth.
 *
 * Only sports configured for it run. The chart endpoint is American
 * football's shape, and nothing has been checked against the others.
 */
class SyncDepthCharts implements ShouldQueue
{
    use Queueable, TracksJobRun;

    public int $tries = 2;

    public int $backoff = 300;

    public function __construct(
        public readonly Sport $sport,
        public readonly TriggerSource $triggeredBy = TriggerSource::Schedule,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('depth-charts:'.$this->sport->value))->expireAfter(1800)];
    }

    public function handle(EspnDepthChartSource $source, JobRunRecorder $recorder): void
    {
        $this->tracked(
            "ingest:depth-charts:{$this->sport->value}",
            function (JobRun $run) use ($source, $recorder): array {
                if (! in_array($this->sport->value, (array) config('edge.value.depth_chart_sports', []), true)) {
                    $recorder->skip($run, 'Depth charts are not configured for '.$this->sport->value.'.');

                    return [];
                }

                $source->withJobRun($run->id);

                $stats = $source->sync($this->sport);

                return [
                    'records_in' => $stats['teams'],
                    'records_out' => $stats['entries'],
                    'unresolved_teams' => $stats['unresolved'],
                    'source' => $source->key(),
                ];
            },
            $this->sport,
        );
    }
}
