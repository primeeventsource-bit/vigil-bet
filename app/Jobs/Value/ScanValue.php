<?php

declare(strict_types=1);

namespace App\Jobs\Value;

use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\JobRun;
use App\Services\Value\ValueScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Sweeps the board and records every candidate, pick or pass.
 *
 * Runs after the odds pull and the prediction pass, because it compares one
 * against the other and a scan is only as fresh as the prices in it. Each
 * run writes a new scan rather than updating the last one: the Today screen
 * then renders one consistent snapshot instead of a board that shifts while
 * it is being read, and the history of what was flagged when survives.
 */
class ScanValue implements ShouldQueue
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
        public readonly int $horizonHours = 72,
        public readonly TriggerSource $triggeredBy = TriggerSource::Schedule,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('value:scan:'.($this->sport->value ?? 'all')))->expireAfter(900)];
    }

    public function handle(ValueScanner $scanner): void
    {
        $this->tracked(
            'value:scan',
            function (JobRun $run) use ($scanner): array {
                $scan = $scanner->scan($this->sport, $this->horizonHours, jobRunId: $run->id);

                return [
                    'records_in' => $scan->candidates,
                    'records_out' => $scan->picks,
                    'value_scan_id' => $scan->id,
                    'candidates' => $scan->candidates,
                    'picks' => $scan->picks,
                ];
            },
            $this->sport,
        );
    }
}
