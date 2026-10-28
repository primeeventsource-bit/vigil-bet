<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Record\OpinionPublisher;
use Illuminate\Console\Command;

/**
 * Publishes the day's opinions.
 *
 * Separate from vigil:publish-picks, which writes the public record and
 * only ever from a proven market. This writes the weaker, published
 * claim: the model's probabilities have been measured to land close to
 * the truth, so here is what it thinks.
 *
 * Safe to re-run. The database refuses a second opinion on the same
 * selection, so an hourly run republishes nothing.
 */
class PublishOpinionsCommand extends Command
{
    protected $signature = 'vigil:publish-opinions';

    protected $description = "Publish the engine's own opinions for games that have not started";

    public function handle(OpinionPublisher $publisher): int
    {
        $outcome = $publisher->run();

        $this->info("published {$outcome['published']} of {$outcome['candidates']} publishable candidates");

        foreach ($outcome['skipped'] as $reason => $count) {
            if ($count > 0) {
                $this->line('  skipped '.str_replace('_', ' ', $reason).": {$count}");
            }
        }

        return self::SUCCESS;
    }
}
