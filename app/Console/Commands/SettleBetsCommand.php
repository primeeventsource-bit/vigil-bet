<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Bets\BetSettler;
use Illuminate\Console\Command;

/**
 * Grades pending bets from the final scores Edge Lab mirrored.
 *
 * Automatic, because §8 asks for auto-grading, and because a record
 * settled by hand is a record somebody can be accused of curating. The
 * event log records whether each settlement came from a score or from a
 * person, and everything this command writes came from a score.
 *
 * A bet whose event has no final score stays pending and is counted as
 * skipped. Guessing at an outcome to clear a queue is how a record stops
 * being one.
 */
class SettleBetsCommand extends Command
{
    protected $signature = 'vigil:settle-bets';

    protected $description = 'Grade pending bets from mirrored final scores and record closing line value';

    public function handle(BetSettler $settler): int
    {
        $result = $settler->run();

        $this->components->twoColumnDetail('Settled', (string) $result['settled']);
        $this->components->twoColumnDetail('Closing prices recorded', (string) $result['clv']);
        $this->components->twoColumnDetail(
            'Still pending',
            $result['skipped'] === 0 ? '0' : '<fg=yellow>'.$result['skipped'].'</> awaiting a final score',
        );

        return self::SUCCESS;
    }
}
