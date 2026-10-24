<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InjuryStatus;
use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Ingest\SyncInjuries;
use App\Models\InjuryReport;
use App\Services\Ingestion\EspnInjurySource;
use Illuminate\Console\Command;

/**
 * Pull availability reports and say what came back.
 *
 * The unrecognised-status count is printed even when it is zero, because
 * the number only means anything if you know it is being watched.
 */
class InjuriesCommand extends Command
{
    protected $signature = 'edge:injuries
                            {sport=all : Sport key, or "all"}
                            {--sync}';

    protected $description = 'Pull current injury and availability reports';

    public function handle(EspnInjurySource $source): int
    {
        $sport = mb_strtolower((string) $this->argument('sport'));

        if ($sport !== 'all' && Sport::tryFrom($sport) === null) {
            $this->error('Unknown sport. Known: '.implode(', ', Sport::values()).', all');

            return self::FAILURE;
        }

        $sports = $sport === 'all' ? Sport::enabled() : [Sport::from($sport)];

        if (! $this->option('sync')) {
            foreach ($sports as $each) {
                dispatch(new SyncInjuries($each, TriggerSource::Manual));
            }

            $this->line('Queued '.count($sports).'.');

            return self::SUCCESS;
        }

        foreach ($sports as $each) {
            if (! $source->isAvailable($each)) {
                $this->line(sprintf('  %-8s no source', $each->value));

                continue;
            }

            $stats = $source->sync($each);

            $this->line(sprintf(
                '  %-8s %4d reports · %2d teams · %d unresolved · %d unrecognised status',
                $each->value,
                $stats['reports'],
                $stats['teams'],
                $stats['unresolved'],
                $stats['unknown_status'],
            ));

            if ($stats['unknown_status'] > 0) {
                $this->warn(sprintf(
                    '    %d report%s carried a status this app does not map. They are stored as '
                    .'unknown with the source wording kept, and contribute no availability number.',
                    $stats['unknown_status'],
                    $stats['unknown_status'] === 1 ? '' : 's',
                ));
            }
        }

        $this->breakdown();

        return self::SUCCESS;
    }

    private function breakdown(): void
    {
        $counts = InjuryReport::query()
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        if ($counts->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('<options=bold>Stored reports by status</>');

        foreach ($counts as $status => $count) {
            $label = InjuryStatus::tryFrom((string) $status)?->label() ?? (string) $status;

            $this->line(sprintf('  %-22s %d', $label, $count));
        }
    }
}
