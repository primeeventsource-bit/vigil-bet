<?php

declare(strict_types=1);

namespace App\Jobs\Modeling;

use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\JobRun;
use App\Services\Modeling\Forecaster;
use App\Services\Ops\JobRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Prices the next few days of games with the active model.
 *
 * Runs often, because a prediction is only worth writing while the game is
 * still ahead of it, and a sport whose slate was published this morning
 * should not wait until tomorrow to be priced. Re-running is cheap and
 * idempotent: one prediction per game per model version, so a second pass
 * over the same slate writes nothing.
 *
 * Having no active model is a skip rather than a failure. A sport that has
 * never been trained is an ordinary state - six sports ship enabled and the
 * owner may only care about two.
 */
class PredictUpcoming implements ShouldQueue
{
    use Queueable, TracksJobRun;

    public int $tries = 2;

    // Not an immediate retry. Both the database and the application
    // hibernate, so the failure this guards against is a connection
    // dropped by something that is still waking up - and a second
    // attempt in the same second meets exactly the same dead socket.
    public int $backoff = 60;

    public int $timeout = 600;

    public function __construct(
        public readonly Sport $sport,
        public readonly int $horizonHours = 72,
        public readonly TriggerSource $triggeredBy = TriggerSource::Schedule,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('predict:'.$this->sport->value))->expireAfter(900)];
    }

    public function handle(Forecaster $forecaster, JobRunRecorder $recorder): void
    {
        $this->tracked(
            'model:predict',
            function (JobRun $run) use ($forecaster, $recorder): array {
                $result = $forecaster->forecast($this->sport, $this->horizonHours);

                if (isset($result['refusal'])) {
                    $recorder->skip($run, $result['refusal']);

                    return [];
                }

                return [
                    'records_in' => $result['events'],
                    'records_out' => $result['written'],
                ] + $result;
            },
            $this->sport,
        );
    }
}
