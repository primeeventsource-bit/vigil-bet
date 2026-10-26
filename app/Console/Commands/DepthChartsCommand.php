<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Ingest\SyncDepthCharts;
use App\Services\Ingestion\EspnDepthChartSource;
use App\Services\Ops\JobRunRecorder;
use Illuminate\Console\Command;

/**
 * Pulls depth charts for a sport, or for every sport configured for them.
 *
 * Run directly rather than queued, because the reason to type this is to
 * watch it work.
 */
class DepthChartsCommand extends Command
{
    protected $signature = 'ingest:depth-charts {sport=all}';

    protected $description = 'Read each team\'s depth chart from the provider';

    public function handle(): int
    {
        $requested = (string) $this->argument('sport');

        $sports = $requested === 'all'
            ? Sport::enabled()
            : array_filter(Sport::enabled(), fn (Sport $s) => $s->value === $requested);

        if ($sports === []) {
            $this->error("Unknown sport [{$requested}].");

            return self::FAILURE;
        }

        foreach ($sports as $sport) {
            $this->line("depth charts: {$sport->value}");

            (new SyncDepthCharts($sport, TriggerSource::Manual))->handle(
                app(EspnDepthChartSource::class),
                app(JobRunRecorder::class),
            );
        }

        return self::SUCCESS;
    }
}
