<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TriggerSource;
use App\Jobs\Ingest\SyncProbableStarters;
use App\Models\ProbableStarter;
use App\Services\Ingestion\MlbProbablePitcherSource;
use Illuminate\Console\Command;

/**
 * Pull probable starting pitchers and say what came back.
 *
 * Prints the games with no pitcher named as plainly as the ones with, since
 * days out that is most of the slate and a count of starters alone would
 * read as a failure.
 */
class StartersCommand extends Command
{
    protected $signature = 'edge:starters
                            {--days=5 : How far ahead to look}
                            {--sync}';

    protected $description = 'Pull probable starting pitchers (MLB)';

    public function handle(MlbProbablePitcherSource $source): int
    {
        if (! $this->option('sync')) {
            dispatch(new SyncProbableStarters(
                (int) $this->option('days'),
                TriggerSource::Manual,
            ));

            $this->line('Queued.');

            return self::SUCCESS;
        }

        $stats = $source->sync((int) $this->option('days'));

        $this->newLine();
        $this->line(sprintf(
            '<comment>Probable pitchers</comment> · %d named · %d games matched · %d sides with nobody named yet',
            $stats['starters'],
            $stats['games'],
            $stats['without_pitcher'],
        ));

        if ($stats['unmatched_games'] > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '  %d MLB game%s whose id this app does not hold. That is a schedule that has not '
                .'been pulled rather than a missing pitcher - run edge:stats:refresh mlb first.',
                $stats['unmatched_games'],
                $stats['unmatched_games'] === 1 ? '' : 's',
            ));
        }

        $this->newLine();
        $this->line(sprintf('Stored: %d probable starters.', ProbableStarter::query()->count()));
        $this->line('<fg=gray>Pitchers only. The NHL publishes no equivalent for starting goalies,</>');
        $this->line('<fg=gray>so that role is left empty rather than guessed at.</>');

        return self::SUCCESS;
    }
}
