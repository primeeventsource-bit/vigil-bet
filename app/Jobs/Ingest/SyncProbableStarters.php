<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\JobRun;
use App\Services\Ingestion\MlbProbablePitcherSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Probable starting pitchers for the coming days.
 *
 * MLB only. The NHL publishes nothing equivalent for starting goalies, so
 * there is no second half of this job to write and the goalie role stays
 * empty rather than being filled with a guess.
 */
class SyncProbableStarters implements ShouldQueue
{
    use Queueable, TracksJobRun;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public readonly int $days = 5,
        public readonly TriggerSource $triggeredBy = TriggerSource::Schedule,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('probable-starters'))->expireAfter(900)];
    }

    public function handle(MlbProbablePitcherSource $source): void
    {
        $this->tracked('ingest:starters', function (JobRun $run) use ($source): array {
            $stats = $source->withJobRun($run->id)->sync($this->days);

            return [
                'records_in' => $stats['games'],
                'records_out' => $stats['starters'],
                // A game whose gamePk we do not hold is a schedule that has
                // not been pulled, not a missing pitcher, and the two are
                // worth telling apart on the Jobs screen.
                'unmatched_games' => $stats['unmatched_games'],
                'without_pitcher' => $stats['without_pitcher'],
            ];
        });
    }
}
