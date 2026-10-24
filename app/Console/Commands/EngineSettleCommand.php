<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TriggerSource;
use App\Jobs\Bets\RecordBetClv;
use App\Jobs\Bets\SettleBets;
use Illuminate\Console\Command;

class EngineSettleCommand extends Command
{
    protected $signature = 'edge:settle {--clv-only : Only stamp closing line value, do not grade}
                                       {--sync}';

    protected $description = 'Grade finished bets and record what the line closed at';

    public function handle(): int
    {
        $jobs = $this->option('clv-only')
            ? [new RecordBetClv(triggeredBy: TriggerSource::Manual)]
            : [new SettleBets(triggeredBy: TriggerSource::Manual), new RecordBetClv(triggeredBy: TriggerSource::Manual)];

        foreach ($jobs as $job) {
            $this->option('sync') ? dispatch_sync($job) : dispatch($job);
        }

        $this->info($this->option('sync') ? 'Settled.' : 'Queued.');

        return self::SUCCESS;
    }
}
