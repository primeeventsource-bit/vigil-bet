<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Alerts\AlertDispatcher;
use App\Services\Alerts\AlertRaiser;
use Illuminate\Console\Command;

/**
 * Raises alerts and delivers them.
 *
 * Two phases in one command, because they are two halves of one promise
 * and running them apart would mean an alert could be raised and never
 * sent by a scheduler that only half worked.
 *
 * Safe to re-run: an alert is unique per subject and a delivery is unique
 * per (alert, customer, channel), both enforced in the database. The
 * failure mode this guards against is not a missing notification - it is
 * the same push eleven times, which gets an application uninstalled.
 */
class AlertsCommand extends Command
{
    protected $signature = 'vigil:alerts
        {--raise-only : Find new alerts and send nothing}
        {--send-only : Deliver what is already raised}';

    protected $description = 'Raise alerts for new picks and watched line moves, then deliver them';

    public function handle(AlertRaiser $raiser, AlertDispatcher $dispatcher): int
    {
        if (! $this->option('send-only')) {
            $raised = $raiser->run();

            $this->components->twoColumnDetail('New picks', (string) $raised['picks']);
            $this->components->twoColumnDetail('Line moves', (string) $raised['moves']);
        }

        if ($this->option('raise-only')) {
            return self::SUCCESS;
        }

        $sent = $dispatcher->run();

        $this->components->twoColumnDetail('Sent', (string) $sent['sent']);
        $this->components->twoColumnDetail('Skipped', (string) $sent['skipped']);
        $this->components->twoColumnDetail(
            'Failed',
            $sent['failed'] === 0 ? '0' : '<fg=red>'.$sent['failed'].'</>',
        );

        return self::SUCCESS;
    }
}
