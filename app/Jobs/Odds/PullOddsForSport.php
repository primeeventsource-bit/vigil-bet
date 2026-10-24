<?php

declare(strict_types=1);

namespace App\Jobs\Odds;

use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\JobRun;
use App\Services\Odds\OddsSnapshotWriter;
use App\Services\Odds\PullCadence;
use App\Services\Odds\TheOddsApiClient;
use App\Services\Ops\JobRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One pull of every book, market and side for one sport, stored as
 * append-only snapshots. Costs exactly one API request.
 */
class PullOddsForSport implements ShouldQueue
{
    use Queueable, TracksJobRun;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly Sport $sport,
        public readonly bool $force = false,
        public readonly TriggerSource $triggeredBy = TriggerSource::Schedule,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('odds-pull:'.$this->sport->value))->expireAfter(300)];
    }

    public function handle(
        TheOddsApiClient $client,
        OddsSnapshotWriter $writer,
        PullCadence $cadence,
        JobRunRecorder $recorder,
    ): void {
        $this->tracked(
            $cadence->jobKey($this->sport),
            function (JobRun $run) use ($client, $writer, $cadence, $recorder): array {
                if (! $client->isConfigured()) {
                    $recorder->skip($run, 'ODDS_API_KEY is not set.');

                    return [];
                }

                if (! $this->force && ! $cadence->isDue($this->sport)) {
                    $recorder->skip($run, 'Not due yet under the configured cadence.', [
                        'interval_minutes' => $cadence->intervalMinutes($this->sport),
                    ]);

                    return [];
                }

                if (! $client->hasBudget()) {
                    $recorder->skip($run, 'Monthly API quota reserve reached; refusing to pull.');

                    return [];
                }

                $events = $client->oddsForSport($this->sport, $run->id);
                $written = $writer->write($events);

                return [
                    'records_in' => count($events),
                    'records_out' => $written['snapshots'],
                    'events_matched' => $written['events_matched'],
                    'events_unmatched' => $written['events_unmatched'],
                ];
            },
            $this->sport,
        );
    }
}
