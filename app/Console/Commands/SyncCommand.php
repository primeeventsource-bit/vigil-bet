<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\EdgeLab\SyncBoard;
use App\Jobs\EdgeLab\SyncEvents;
use App\Jobs\EdgeLab\SyncModels;
use App\Models\SyncRun;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'vigil:sync
                            {feed? : board, events or models; omit for all three}
                            {--sync : Run inline instead of queueing}';

    protected $description = 'Pull Edge Lab into Vigil read models';

    /** @var array<string, class-string> */
    private const JOBS = [
        'board' => SyncBoard::class,
        'events' => SyncEvents::class,
        'models' => SyncModels::class,
    ];

    public function handle(): int
    {
        if (! config('edgelab.enabled')) {
            $this->warn('The engine is switched off, so there is nothing to read.');
            $this->line('<fg=gray>Every sync will record a skip rather than failing. See config/edgelab.php.</>');

            return self::SUCCESS;
        }

        $feed = $this->argument('feed');
        $feeds = $feed === null ? array_keys(self::JOBS) : [mb_strtolower((string) $feed)];

        foreach ($feeds as $name) {
            if (! isset(self::JOBS[$name])) {
                $this->error("Unknown feed '{$name}'. Known: ".implode(', ', array_keys(self::JOBS)));

                return self::FAILURE;
            }
        }

        foreach ($feeds as $name) {
            $job = new (self::JOBS[$name]);

            $this->option('sync') ? dispatch_sync($job) : dispatch($job);

            $run = SyncRun::query()->feed($name)->latestFirst()->first();

            $this->line(sprintf(
                '%-7s %s',
                $name,
                $this->option('sync')
                    ? sprintf(
                        '%s - %d fetched, %d new, cursor %s%s',
                        $run?->status ?? 'unknown',
                        $run?->records_fetched ?? 0,
                        $run?->records_written ?? 0,
                        $run?->cursor_after ?? 'none',
                        $run?->message ? ' - '.$run->message : '',
                    )
                    : 'queued',
            ));
        }

        return self::SUCCESS;
    }
}
