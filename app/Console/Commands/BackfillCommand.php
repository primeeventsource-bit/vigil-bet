<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SeasonType;
use App\Enums\Sport;
use App\Jobs\Ingest\BackfillSeasonChunk;
use App\Jobs\Ingest\ImportHistoricalLines;
use App\Services\Ingestion\ProvidesHistoricalLines;
use App\Services\Ingestion\SourceRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class BackfillCommand extends Command
{
    protected $signature = 'edge:backfill
                            {sport : Sport key, or "all"}
                            {seasons=5 : How many past seasons to load, on top of the current one}
                            {--no-lines : Skip historical closing lines even where they are available}
                            {--sync : Run inline instead of queueing, for local debugging}';

    protected $description = 'Queue chunked backfill jobs for schedules, results and (where free) historical closing lines';

    public function handle(SourceRegistry $registry): int
    {
        $sports = $this->resolveSports();

        if ($sports === []) {
            $this->error('Unknown sport. Known: '.implode(', ', Sport::values()).', all');

            return self::FAILURE;
        }

        $seasons = max(1, (int) $this->argument('seasons'));
        $currentSeason = (int) now()->year;
        $queued = 0;

        foreach ($sports as $sport) {
            $source = $registry->forRole($sport, 'schedules');

            if ($source === null || ! $source->isAvailable()) {
                $this->warn(sprintf(
                    '%s: no available schedule source (%s). Skipping.',
                    $sport->label(),
                    $source?->key() ?? 'none configured',
                ));

                continue;
            }

            $hasLines = $source instanceof ProvidesHistoricalLines;

            $this->line(sprintf(
                '%s via %s (%s)%s',
                $sport->label(),
                $source->key(),
                $source->reliability()->label(),
                $hasLines ? ' - includes historical closing lines' : ' - no free line history',
            ));

            /*
             * From zero, so the current season is loaded too.
             *
             * It used to start at one, which loaded the seasons before
             * this one and never this one - on the reasoning that the
             * live sync keeps the current season current. It does, from
             * whenever the app started watching: the schedule job pulls
             * upcoming games and the results job pulls recent ones, and
             * neither reaches back to March.
             *
             * Measured on 25 October 2026, that left 14 finished NFL
             * games in a season eight weeks old and four MLB games in a
             * season that had just ended, while the models predicting
             * those sports trained on what history there was and the
             * research brief looked for recent form in it.
             *
             * The current season is not a past season, so it is not
             * counted against the argument - a backfill asked for five
             * seasons still gets five, plus the one being predicted.
             */
            for ($i = 0; $i <= $seasons; $i++) {
                $season = $currentSeason - $i;

                $jobs = [new BackfillSeasonChunk($sport, $season, SeasonType::Regular)];

                if ($hasLines && ! $this->option('no-lines')) {
                    // Lines must land after the games they belong to.
                    $jobs[] = new ImportHistoricalLines($sport, $season);
                }

                if ($this->option('sync')) {
                    foreach ($jobs as $job) {
                        dispatch_sync($job);
                    }
                } else {
                    Bus::chain($jobs)->dispatch();
                }

                $queued += count($jobs);
                $this->line("  queued season {$season}");
            }
        }

        $this->newLine();
        $this->info($this->option('sync')
            ? "Ran {$queued} backfill jobs inline."
            : "Queued {$queued} backfill jobs. Run a worker: php artisan queue:work");

        $this->line('Watch progress: php artisan edge:doctor, or the Jobs screen.');

        return self::SUCCESS;
    }

    /** @return array<int, Sport> */
    private function resolveSports(): array
    {
        $argument = mb_strtolower((string) $this->argument('sport'));

        if ($argument === 'all') {
            return Sport::enabled();
        }

        $sport = Sport::tryFrom($argument);

        return $sport ? [$sport] : [];
    }
}
