<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\JobRun;
use App\Services\Ingestion\OpenMeteoWeatherSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Forecasts for kickoff at outdoor venues.
 *
 * Runs a few times a day, and every run writes a new row rather than
 * replacing the last. That is the whole point: a forecast three days out
 * and a forecast three hours out are different pieces of information, and
 * the difference between them is the forecast error a model would have to
 * live with. Collapsing them to one row would throw that away.
 */
class SyncWeather implements ShouldQueue
{
    use Queueable, TracksJobRun;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public readonly int $horizonHours = 168,
        public readonly TriggerSource $triggeredBy = TriggerSource::Schedule,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('weather'))->expireAfter(900)];
    }

    public function handle(OpenMeteoWeatherSource $source): void
    {
        $this->tracked('ingest:weather', function (JobRun $run) use ($source): array {
            $stats = $source->withJobRun($run->id)->syncUpcoming($this->horizonHours);

            return [
                'records_out' => $stats['written'],
                'skipped_indoor' => $stats['skipped_indoor'],
                // Surfaced rather than buried: a venue with no coordinates
                // silently gets no weather forever, and the Jobs screen is
                // the only place that would ever show it.
                'skipped_unlocated' => $stats['skipped_unlocated'],
            ];
        });
    }
}
