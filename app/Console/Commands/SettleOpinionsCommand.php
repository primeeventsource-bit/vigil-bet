<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Record\OpinionSettler;
use Illuminate\Console\Command;

/**
 * Grades published opinions against what happened.
 *
 * The half that makes the other half honest. An opinion is published on
 * the claim that the model's probabilities land close to the truth, and
 * the only thing that turns that from an assertion into something a
 * customer can check is every one of them being graded in public.
 */
class SettleOpinionsCommand extends Command
{
    protected $signature = 'vigil:settle-opinions';

    protected $description = 'Grade published opinions against the final score';

    public function handle(OpinionSettler $settler): int
    {
        $outcome = $settler->run();

        $this->info("settled {$outcome['settled']}");
        $this->line("  still waiting on a result: {$outcome['unresolved']}");

        return self::SUCCESS;
    }
}
