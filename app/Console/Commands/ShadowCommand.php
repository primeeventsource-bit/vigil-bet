<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Record\ShadowRecorder;
use Illuminate\Console\Command;

/**
 * Records and settles the shadow record.
 *
 * Deliberately a separate command from vigil:publish-picks rather than a
 * flag on it. The publisher writes the thing customers are sold; this
 * writes an experiment nobody is shown. Sharing a command would make it
 * one mistake away from sharing a table.
 */
class ShadowCommand extends Command
{
    protected $signature = 'vigil:shadow
                            {--settle-only : Settle what is already recorded and record nothing new}';

    protected $description = 'Record what the engine would have bet without the verdict gate, and settle it';

    public function handle(ShadowRecorder $recorder): int
    {
        if (! $this->option('settle-only')) {
            $written = $recorder->record();

            $this->line(sprintf(
                '  recorded %d, skipped %s',
                $written['recorded'],
                collect($written['skipped'])->filter()->map(fn ($n, $k) => "{$k} {$n}")->implode(', ') ?: 'none',
            ));
        }

        $settled = $recorder->settle();

        $this->line(sprintf(
            '  settled %d, still waiting on a result %d',
            $settled['settled'],
            $settled['unresolved'],
        ));

        return self::SUCCESS;
    }
}
