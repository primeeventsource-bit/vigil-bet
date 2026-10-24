<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Odds\PullOddsForSport;
use App\Services\Odds\PullCadence;
use App\Services\Odds\TheOddsApiClient;
use Illuminate\Console\Command;

class PullOddsCommand extends Command
{
    protected $signature = 'edge:odds:pull
                            {sport? : Sport key; omit for every enabled sport that is due}
                            {--force : Ignore the cadence and pull now}
                            {--sync : Run inline instead of queueing}';

    protected $description = 'Pull current odds snapshots from The Odds API';

    public function handle(PullCadence $cadence, TheOddsApiClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('ODDS_API_KEY is not set. Add it to .env first.');

            return self::FAILURE;
        }

        $sports = $this->resolveSports();

        if ($sports === []) {
            $this->error('Unknown sport. Known: '.implode(', ', Sport::values()));

            return self::FAILURE;
        }

        $dispatched = 0;

        foreach ($sports as $sport) {
            $due = $this->option('force') || $cadence->isDue($sport);

            if (! $due) {
                $interval = $cadence->intervalMinutes($sport);

                $this->line(sprintf(
                    '%-6s skipped - %s',
                    $sport->value,
                    $interval === null
                        ? 'no upcoming games on the board'
                        : "next pull due every {$interval} min",
                ));

                continue;
            }

            $job = new PullOddsForSport($sport, (bool) $this->option('force'), TriggerSource::Manual);

            $this->option('sync') ? dispatch_sync($job) : dispatch($job);
            $dispatched++;

            $this->line(sprintf('%-6s %s', $sport->value, $this->option('sync') ? 'pulled' : 'queued'));
        }

        $this->info("{$dispatched} sport(s) handled.");

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
