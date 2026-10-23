<?php

declare(strict_types=1);

namespace App\Jobs\EdgeLab;

use App\Models\SyncRun;
use App\Models\VlEvent;
use App\Services\EdgeLab\EdgeLabClient;
use App\Services\EdgeLab\Mirror;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;

/** Pulls the schedule: teams, kickoff, status and final score. */
class SyncEvents implements ShouldQueue
{
    use Queueable, SyncsFromEdgeLab;

    public int $tries = 3;

    public int $backoff = 60;

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('edgelab-sync:events'))->expireAfter(600)];
    }

    protected function feed(): string
    {
        return 'events';
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
                $page = $client->events($cursor);
                $pages++;
                $fetched += $page->count();

                foreach ($page->rows as $row) {
                    $written += $mirror->write(VlEvent::class, $row, $this->columns($row), $observedAt) ? 1 : 0;

                    // From the rows, not the meta. See SyncBoard.
                    $cursor = max((int) $cursor, (int) $row['id']);
                }
            } while ($page->hasMore() && $pages < 25);

            return ['fetched' => $fetched, 'written' => $written, 'cursor' => $cursor, 'pages' => $pages];
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function columns(array $row): array
    {
        $home = (array) ($row['home'] ?? []);
        $away = (array) ($row['away'] ?? []);

        return [
            'edge_lab_id' => (int) $row['id'],
            'sport' => $row['sport'] ?? 'unknown',
            'starts_at' => isset($row['starts_at']) ? Carbon::parse((string) $row['starts_at']) : null,
            'status' => $row['status'] ?? null,
            'home_name' => $home['name'] ?? null,
            'home_abbreviation' => $home['abbreviation'] ?? null,
            'away_name' => $away['name'] ?? null,
            'away_abbreviation' => $away['abbreviation'] ?? null,
            'final_home_score' => $row['final_home_score'] ?? null,
            'final_away_score' => $row['final_away_score'] ?? null,
            'completed_at' => isset($row['completed_at']) ? Carbon::parse((string) $row['completed_at']) : null,
        ];
    }
}
