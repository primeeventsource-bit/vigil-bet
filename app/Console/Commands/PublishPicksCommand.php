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
 * Re-running it is safe. Publishing is one row per selection for ever,
 * and settling one result per pick.
 *
 * The two are not guarded equally, and the difference is worth knowing.
 * A second result is refused by a unique key in the database. A second
 * pick is refused by the publisher reading the table first, which is a
 * check and not a constraint - so it holds for a re-run and would not
 * hold against two of these running at the same instant. The schedule
 * keeps them apart with a lock; the record is not yet defended by the
 * database the way the kickoff rule is.
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
