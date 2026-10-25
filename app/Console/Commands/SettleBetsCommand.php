<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Bets\BetSettler;
use App\Services\Parlay\ParlaySettler;
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

    public function handle(BetSettler $settler, ParlaySettler $parlays): int
    {
        $result = $settler->run();

        $this->components->twoColumnDetail('Straight bets settled', (string) $result['settled']);
        $this->components->twoColumnDetail('Closing prices recorded', (string) $result['clv']);
        $this->components->twoColumnDetail(
            'Still pending',
            $result['skipped'] === 0 ? '0' : '<fg=yellow>'.$result['skipped'].'</> awaiting a final score',
        );

        /*
         * Parlays in the same pass, because their legs are graded from
         * the same final scores. Settling them an hour apart would leave
         * a parlay pending beside the straight bet on the same game,
         * which reads as a bug whether or not it is one.
         */
        $parlayResult = $parlays->run();

        $this->components->twoColumnDetail('Parlays settled', (string) $parlayResult['settled']);
        $this->components->twoColumnDetail(
            'Parlays still pending',
            $parlayResult['pending'] === 0 ? '0' : '<fg=yellow>'.$parlayResult['pending'].'</> with a leg to come',
        );

        return self::SUCCESS;
    }
}
