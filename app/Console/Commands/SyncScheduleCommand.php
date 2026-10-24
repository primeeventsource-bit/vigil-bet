<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Ingest\SyncResults;
use App\Jobs\Ingest\SyncSchedules;
use Illuminate\Console\Command;

class SyncScheduleCommand extends Command
{
    protected $signature = 'edge:stats:refresh
                            {sport? : Sport key; omit for every enabled sport}
                            {--days=10 : Days ahead to pull schedules for}
                            {--lookback=3 : Days back to pull results for}
                            {--schedules-only}
                            {--results-only}
                            {--sync}';

    protected $description = 'Sync upcoming schedules and recent results from each sport data source';

    public function handle(): int
    {
        $sports = $this->resolveSports();

        if ($sports === []) {
            $this->error('Unknown sport. Known: '.implode(', ', Sport::values()));

            return self::FAILURE;
        }

        foreach ($sports as $sport) {
            $jobs = [];

            if (! $this->option('results-only')) {
                $jobs[] = new SyncSchedules($sport, (int) $this->option('days'), TriggerSource::Manual);
            }

            if (! $this->option('schedules-only')) {
                $jobs[] = new SyncResults($sport, (int) $this->option('lookback'), TriggerSource::Manual);
            }

            foreach ($jobs as $job) {
                $this->option('sync') ? dispatch_sync($job) : dispatch($job);
            }

            $this->line(sprintf('%-6s %d job(s) %s', $sport->value, count($jobs), $this->option('sync') ? 'run' : 'queued'));
        }

        return self::SUCCESS;
    }

    /** @return array<int, Sport> */
    private function resolveSports(): array
    {
        $argument = $this->argument('sport');

        if ($argument === null) {
            return Sport::enabled();
        }

        $sport = Sport::tryFrom(mb_strtolower((string) $argument));

        return $sport ? [$sport] : [];
    }
}
