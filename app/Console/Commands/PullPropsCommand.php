<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Odds\PullPropsForSport;
use App\Services\Odds\PullCadence;
use App\Services\Odds\TheOddsApiClient;
use Illuminate\Console\Command;

class PullPropsCommand extends Command
{
    protected $signature = 'edge:odds:props
                            {sport? : Sport key; omit for every sport with props configured}
                            {--force : Ignore the cadence and pull now}
                            {--sync : Run inline instead of queueing}';

    protected $description = 'Pull player prop snapshots from The Odds API, for display and line shopping only';

    public function handle(PullCadence $cadence, TheOddsApiClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('ODDS_API_KEY is not set. Add it to .env first.');

            return self::FAILURE;
        }

        if (! (bool) config('edge.odds_api.props.enabled')) {
            $this->warn('Player props are switched off. Set ODDS_API_PROPS_ENABLED=true to turn them on.');

            return self::SUCCESS;
        }

        $sports = $this->resolveSports();

        if ($sports === []) {
            $this->error('Unknown sport, or no prop markets configured for it. With props: '
                .(implode(', ', array_map(fn (Sport $s) => $s->value, Sport::withProps())) ?: 'none'));

            return self::FAILURE;
        }

        $dispatched = 0;

        foreach ($sports as $sport) {
            $due = $this->option('force') || $cadence->isDueForProps($sport);

            if (! $due) {
                $interval = $cadence->propsIntervalMinutes($sport);

                $this->line(sprintf(
                    '%-6s skipped - %s',
                    $sport->value,
                    $interval === null
                        ? 'no games inside the props horizon'
                        : "next props pull due every {$interval} min",
                ));

                continue;
            }

            $job = new PullPropsForSport($sport, (bool) $this->option('force'), TriggerSource::Manual);

            $this->option('sync') ? dispatch_sync($job) : dispatch($job);
            $dispatched++;

            $this->line(sprintf(
                '%-6s %s (%s)',
                $sport->value,
                $this->option('sync') ? 'pulled' : 'queued',
                implode(', ', $sport->propMarkets()),
            ));
        }

        $this->info("{$dispatched} sport(s) handled.");
        $this->line('<fg=gray>Props are display and line shopping only. No prop row can become a pick.</>');

        return self::SUCCESS;
    }

    /** @return array<int, Sport> */
    private function resolveSports(): array
    {
        $argument = $this->argument('sport');

        if ($argument === null) {
            return Sport::withProps();
        }

        $sport = Sport::tryFrom(mb_strtolower((string) $argument));

        return $sport !== null && $sport->hasProps() ? [$sport] : [];
    }
}
