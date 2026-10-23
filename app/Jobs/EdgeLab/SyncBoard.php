<?php

declare(strict_types=1);

namespace App\Jobs\EdgeLab;

use App\Models\SyncRun;
use App\Models\VlBoardEntry;
use App\Services\EdgeLab\EdgeLabClient;
use App\Services\EdgeLab\Mirror;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;

/**
 * Pulls the value board into Vigil's mirror: every evaluated candidate,
 * pick and pass alike.
 *
 * Passes are kept. They are most of the board and they are the honest part
 * of it - a product that stored only picks could not say "we looked at
 * this and declined", which is the sentence the whole thing is built on.
 */
class SyncBoard implements ShouldQueue
{
    use Queueable, SyncsFromEdgeLab;

    public int $tries = 3;

    public int $backoff = 60;

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('edgelab-sync:board'))->expireAfter(600)];
    }

    protected function feed(): string
    {
        return 'board';
    }

    public function handle(EdgeLabClient $client, Mirror $mirror): void
    {
        $this->tracked($client, function (SyncRun $run) use ($client, $mirror): array {
            $cursor = $run->cursor_before;
            $fetched = 0;
            $written = 0;
            $pages = 0;
            $observedAt = now();

            do {
                $page = $client->board($cursor);
                $pages++;
                $fetched += $page->count();

                foreach ($page->rows as $row) {
                    $written += $mirror->write(VlBoardEntry::class, $row, $this->columns($row), $observedAt) ? 1 : 0;

                    /*
                     * The cursor comes from the rows, not from the
                     * meta. `next_since` is null once the server has
                     * nothing further, and trusting it alone would mean
                     * a sync that caught up never recorded where it got
                     * to - so the next one would start from the
                     * beginning and re-read the entire history, every
                     * hour, forever.
                     */
                    $cursor = max((int) $cursor, (int) $row['id']);
                }

                // A ceiling on one run. A first sync against a long
                // history should take several ticks rather than one job
                // that runs until a worker kills it half way through.
            } while ($page->hasMore() && $pages < 25);

            return ['fetched' => $fetched, 'written' => $written, 'cursor' => $cursor, 'pages' => $pages];
        });
    }

    /**
     * The fields Vigil reads out of a board row.
     *
     * Everything here is copied, never computed. The whole row is kept in
     * `payload` as well, so a field Vigil does not read yet is still on
     * disk the day it starts reading it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function columns(array $row): array
    {
        return [
            'edge_lab_id' => (int) $row['id'],
            'edge_lab_event_id' => isset($row['event_id']) ? (int) $row['event_id'] : null,
            'value_scan_id' => isset($row['value_scan_id']) ? (int) $row['value_scan_id'] : null,
            'sport' => $row['sport'] ?? null,
            'market' => $row['market'] ?? null,
            'side' => $row['side'] ?? null,
            'line' => $row['line'] ?? null,
            'selection' => $row['selection'] ?? null,
            'model_prob' => $row['model_prob'] ?? null,
            'fair_prob' => $row['fair_prob'] ?? null,
            'edge' => $row['edge'] ?? null,
            'edge_at_price' => $row['edge_at_price'] ?? null,
            'ev_per_dollar' => $row['ev_per_dollar'] ?? null,
            'best_book' => $row['best_book'] ?? null,
            'best_book_title' => $row['best_book_title'] ?? null,
            'best_price_american' => $row['best_price_american'] ?? null,
            'kelly_fraction' => $row['kelly_fraction'] ?? null,
            'suggested_stake_cents' => $row['suggested_stake_cents'] ?? null,
            'market_verdict' => $row['market_verdict'] ?? null,
            'recommendation' => $row['recommendation'] ?? null,
            'pass_reason' => $row['pass_reason'] ?? null,
            'reason' => $row['reason'] ?? null,
            'flagged_at' => isset($row['flagged_at']) ? Carbon::parse((string) $row['flagged_at']) : null,
        ];
    }
}
