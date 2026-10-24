<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WeatherReading;
use App\Services\Ingestion\OpenMeteoWeatherSource;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pull the forecast for kickoff at outdoor venues.
 *
 * Forward only. What a bettor has before a game is a forecast, with a
 * forecast's error in it, and training on what the weather turned out to
 * be is how a backtest comes to look sharper than the tool could ever be
 * in use. OpenMeteoWeatherSource has the full argument.
 */
class WeatherCommand extends Command
{
    protected $signature = 'edge:weather
                            {--hours=168 : How far ahead to forecast}
                            {--backfill= : Days of history to fetch past forecast runs for}
                            {--lead=1 : Which run, in days before kickoff, to backfill}';

    protected $description = 'Fetch kickoff weather for outdoor games';

    public function handle(OpenMeteoWeatherSource $source): int
    {
        if ($this->option('backfill') !== null) {
            return $this->backfill($source);
        }

        $stats = $source->syncUpcoming((int) $this->option('hours'));

        $this->newLine();
        $this->line(sprintf(
            '<comment>Forecasts</comment> · %d written · %d indoor · %d with no coordinates',
            $stats['written'],
            $stats['skipped_indoor'],
            $stats['skipped_unlocated'],
        ));

        if ($stats['skipped_unlocated'] > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '  %d game%s at a venue with no coordinates. Run edge:venues:locate first; a venue '
                .'with a name and nothing else cannot be forecast for.',
                $stats['skipped_unlocated'],
                $stats['skipped_unlocated'] === 1 ? '' : 's',
            ));
        }

        $this->newLine();
        $this->line(sprintf('Stored: %d forecasts.', WeatherReading::query()->count()));
        $this->line('<fg=gray>Forecasts only. Observed game-time weather is deliberately not</>');
        $this->line('<fg=gray>collected: see OpenMeteoWeatherSource.</>');

        return self::SUCCESS;
    }

    /**
     * Past forecast runs, for training.
     *
     * Leaky-looking and not leaky: the series asked for is the run issued
     * this many days before kickoff, so nothing in it was known after the
     * game. The lead is printed because it is the number that makes the
     * training set and the live board the same measurement.
     */
    private function backfill(OpenMeteoWeatherSource $source): int
    {
        $lead = max(1, (int) $this->option('lead'));
        $days = (int) $this->option('backfill');

        $stats = $source->backfillForecasts(
            Carbon::now()->subDays($days)->startOfDay(),
            Carbon::now(),
            $lead,
        );

        $this->newLine();
        $this->line(sprintf(
            '<comment>Past forecast runs</comment> · lead %d day%s · %d written · %d indoor · %d unlocated · %d not in the archive',
            $lead,
            $lead === 1 ? '' : 's',
            $stats['written'],
            $stats['skipped_indoor'],
            $stats['skipped_unlocated'],
            $stats['no_data'],
        ));

        if ($stats['no_data'] > 0) {
            $this->newLine();
            $this->line(sprintf(
                '  <fg=gray>%d game%s the run archive carries nothing usable for. Coverage is not</>',
                $stats['no_data'],
                $stats['no_data'] === 1 ? '' : 's',
            ));
            $this->line('  <fg=gray>uniform - wind runs thin out before 2024 - and those games get no</>');
            $this->line('  <fg=gray>row rather than a row of nulls that would read as calm and dry.</>');
        }

        $this->newLine();
        $this->line(sprintf(
            'Stored: %d readings, %d of them at lead %d.',
            WeatherReading::query()->count(),
            WeatherReading::query()->where('lead_days', $lead)->count(),
            $lead,
        ));

        return self::SUCCESS;
    }
}
