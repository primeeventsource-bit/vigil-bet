<?php

declare(strict_types=1);

namespace App\Jobs\EdgeLab;

use App\Models\SyncRun;
use App\Services\EdgeLab\EdgeLabClient;
use App\Services\EdgeLab\EdgeLabException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every sync job writes exactly one sync_runs row, whatever happens.
 *
 * Success, failure and skip are all recorded, because the sync-health page
 * and the "data delayed" badge both rest on knowing when a feed last
 * worked. A job that failed quietly would leave a board that looks merely
 * quiet, and a customer cannot tell those apart without being told.
 *
 * The cursor only advances on success. A failed page must be re-read, not
 * stepped over: these feeds are append-only and a row skipped is a row
 * Vigil never sees again.
 */
trait SyncsFromEdgeLab
{
    abstract protected function feed(): string;

    /**
     * @param  callable(SyncRun): array{fetched:int, written:int, cursor:?int, pages:int}  $work
     */
    protected function tracked(EdgeLabClient $client, callable $work): void
    {
        $startedAt = now();
        $cursorBefore = SyncRun::cursorFor($this->feed());

        $run = SyncRun::create([
            'feed' => $this->feed(),
            'status' => 'running',
            'cursor_before' => $cursorBefore,
            'started_at' => $startedAt,
        ]);

        if (! $client->isConfigured()) {
            $this->finish($run, 'skipped', 'EDGE_LAB_TOKEN is not set, so there is nothing to read.');

            return;
        }

        try {
            $result = $work($run);

            $run->forceFill([
                'status' => 'success',
                'records_fetched' => $result['fetched'],
                'records_written' => $result['written'],
                'pages' => $result['pages'],
                // Never past where we actually got to.
                'cursor_after' => $result['cursor'] ?? $cursorBefore,
                'duration_ms' => (int) $startedAt->diffInMilliseconds(now()),
                'finished_at' => now(),
            ])->save();
        } catch (EdgeLabException $e) {
            /*
             * An open breaker is not a failure of this run - it is this
             * run correctly declining to make an outage worse. Recorded
             * as a skip so it does not read as Edge Lab having rejected
             * us, which is a different problem with a different fix.
             */
            $this->finish($run, $e->breakerOpen ? 'skipped' : 'failed', $e->getMessage());

            if (! $e->breakerOpen) {
                Log::warning('Edge Lab sync failed', ['feed' => $this->feed(), 'error' => $e->getMessage()]);
            }
        } catch (Throwable $e) {
            $this->finish($run, 'failed', $e->getMessage());

            throw $e;
        }
    }

    private function finish(SyncRun $run, string $status, string $message): void
    {
        $run->forceFill([
            'status' => $status,
            'message' => mb_substr($message, 0, 2000),
            'duration_ms' => (int) $run->started_at->diffInMilliseconds(now()),
            'finished_at' => now(),
            // Left where it was. A run that did not read anything has not
            // earned the right to move the cursor past it.
            'cursor_after' => $run->cursor_before,
        ])->save();
    }
}
