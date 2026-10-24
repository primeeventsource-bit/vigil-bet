<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TriggerSource;
use App\Jobs\Odds\MarkClosingLines;
use App\Models\Event;
use Illuminate\Console\Command;

class MarkClosingLinesCommand extends Command
{
    protected $signature = 'edge:odds:close
                            {--hours=48 : How far back to look for started games}
                            {--all : Queue enough runs to clear every unmarked game, however old}
                            {--sync}';

    protected $description = 'Freeze opening and closing lines for games that have started';

    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->drainBacklog();
        }

        $job = new MarkClosingLines((int) $this->option('hours'), triggeredBy: TriggerSource::Manual);

        $this->option('sync') ? dispatch_sync($job) : dispatch($job);

        $this->info($this->option('sync') ? 'Closing lines marked.' : 'Queued.');

        return self::SUCCESS;
    }

    /**
     * Queue one run per batch of unmarked games.
     *
     * The job picks up any started game with snapshots and no markers,
     * oldest first, capped at `maxEvents` a run. On the nightly schedule
     * that is one batch a tick, so a three-year backlog would take weeks
     * to clear - which is how this engine ended up with 3.3 million odds
     * snapshots and closing lines for 2,870 games out of 20,852.
     *
     * Each run is dispatched separately rather than looped inline: one
     * job that tried to do the lot is what used to exceed the worker
     * timeout and die on max attempts.
     */
    private function drainBacklog(): int
    {
        $backlog = Event::query()
            ->where('starts_at', '<', now())
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('odds_snapshots')
                ->whereColumn('odds_snapshots.event_id', 'events.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('event_line_markers')
                ->whereColumn('event_line_markers.event_id', 'events.id'))
            ->count();

        if ($backlog === 0) {
            $this->components->info('Every started game with snapshots already has its lines marked.');

            return self::SUCCESS;
        }

        $perRun = (new MarkClosingLines)->maxEvents;
        $runs = (int) ceil($backlog / $perRun);

        $this->components->twoColumnDetail('Games with no markers', number_format($backlog));
        $this->components->twoColumnDetail('Games per run', number_format($perRun));
        $this->components->twoColumnDetail('Runs queued', (string) $runs);

        for ($i = 0; $i < $runs; $i++) {
            dispatch(new MarkClosingLines(
                (int) $this->option('hours'),
                triggeredBy: TriggerSource::Manual,
            ));
        }

        $this->newLine();
        $this->components->info('Queued. Each run takes the oldest unmarked games, so they do not overlap.');

        return self::SUCCESS;
    }
}
