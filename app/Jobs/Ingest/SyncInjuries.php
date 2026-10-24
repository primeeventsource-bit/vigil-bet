<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\JobRun;
use App\Services\Ingestion\InjurySources;
use App\Services\Ops\JobRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Writes down who is available, as of now.
 *
 * Runs a few times a day rather than hourly: availability moves on practice
 * reports and pre-game warmups, not continuously, and one request covers a
 * whole league. The value is in having a record of what was known at each
 * point before kickoff, so the cadence matters less than the stamping does.
 */
class SyncInjuries implements ShouldQueue
{
    use Queueable, TracksJobRun;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public readonly Sport $sport,
        public readonly TriggerSource $triggeredBy = TriggerSource::Schedule,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('injuries:'.$this->sport->value))->expireAfter(900)];
    }

    public function handle(InjurySources $sources, JobRunRecorder $recorder): void
    {
        $this->tracked(
            "ingest:injuries:{$this->sport->value}",
            function (JobRun $run) use ($sources, $recorder): array {
                // Which source serves a sport is config's decision now. It
                // used to be whichever class was injected here, which is
                // why the config could say nflverse and the data could say
                // espn for a year without the two ever meeting.
                $source = $sources->forSport($this->sport);

                if ($source === null) {
                    $recorder->skip($run, $sources->reasonFor($this->sport));

                    return [];
                }

                $stats = $source->withJobRun($run->id)->sync($this->sport);

                return [
                    'records_in' => $stats['reports'],
                    'records_out' => $stats['reports'],
                    'teams' => $stats['teams'],
                    'unresolved_teams' => $stats['unresolved'],
                    // Surfaced rather than buried: a run that suddenly
                    // cannot read half the labels is the feed changing
                    // under us, and that has to be visible on the Jobs
                    // screen rather than inferred later from the data.
                    'unknown_status' => $stats['unknown_status'],
                    'source' => $source->key(),
                ];
            },
            $this->sport,
        );
    }
}
