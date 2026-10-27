<?php

declare(strict_types=1);

namespace App\Jobs\Value;

use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\JobRun;
use App\Services\Value\CrossBookScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Looks across books for arbitrages and middles.
 *
 * Runs straight after the odds pull and nowhere else in the chain, because
 * it needs no model and no prediction - it compares books against each
 * other rather than against an opinion. That also makes it the one scan
 * whose value decays in seconds, so it is worth running on the same
 * cadence the prices are pulled on and worth nothing at all on an old
 * snapshot.
 */
class ScanCrossBook implements ShouldQueue
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
        public readonly ?Sport $sport = null,
        public readonly ?int $horizonHours = null,
        public readonly TriggerSource $triggeredBy = TriggerSource::Schedule,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('value:crossbook:'.($this->sport->value ?? 'all')))->expireAfter(900)];
    }

    public function handle(CrossBookScanner $scanner): void
    {
        $this->tracked(
            'value:crossbook',
            function (JobRun $run) use ($scanner): array {
                $result = $scanner->scan(
                    $this->sport,
                    $this->horizonHours ?? (int) config('edge.crossbook.horizon_hours'),
                    jobRunId: $run->id,
                );

                return [
                    'records_out' => $result->found(),
                    'arbitrages' => $result->arbitrages,
                    'middles' => $result->middles,
                ];
            },
            $this->sport,
        );
    }
}
