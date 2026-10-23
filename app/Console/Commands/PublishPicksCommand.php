<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Record\PickPublisher;
use App\Services\Record\PickSettler;
use Illuminate\Console\Command;

/**
 * Publishes today's picks and settles yesterday's.
 *
 * One command for both halves, because they are two ends of the same
 * promise: a pick goes on the public record before the game, and its
 * result goes on afterwards, and neither is ever edited.
 *
 * Re-running it is safe. Publishing is one row per board entry for ever
 * and settling is one result per pick, both enforced in the database -
 * which matters, because a track record that can be padded by running a
 * command twice is not a track record.
 */
class PublishPicksCommand extends Command
{
    protected $signature = 'vigil:publish-picks {--settle-only : Settle what is already published and publish nothing}';

    protected $description = 'Publish proven picks to the public record and settle the ones that have finished';

    public function handle(PickPublisher $publisher, PickSettler $settler): int
    {
        if (! $this->option('settle-only')) {
            $result = $publisher->run();

            $this->components->twoColumnDetail('Published', (string) $result['published']);

            foreach ($result['skipped'] as $reason => $count) {
                if ($count > 0) {
                    $this->components->twoColumnDetail(
                        '  skipped: '.str_replace('_', ' ', $reason),
                        (string) $count,
                    );
                }
            }
        }

        $settled = $settler->run();

        $this->components->twoColumnDetail('Settled', (string) $settled['settled']);
        $this->components->twoColumnDetail(
            'Awaiting a final score',
            $settled['skipped'] === 0 ? '0' : '<fg=yellow>'.$settled['skipped'].'</>',
        );

        return self::SUCCESS;
    }
}
