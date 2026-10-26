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

/**
 * Pulls the schedule: teams, kickoff, status and final score.
 *
 * In two passes, because `events` is the one feed Vigil reads that is not
 * append-only. A game is written when the schedule is published and then
 * *edited in place* when it finishes - same row, same id, new score. A
 * cursor of "every id above the last one I saw" can only ever discover
 * that row once, on the day it was scheduled, with no score on it. That
 * is what happened: every mirrored game stayed frozen at its pre-kickoff
 * state, so nothing that grades against the mirror - the public record,
 * a customer's own bets, their parlays - could ever settle.
 *
 * So:
 *
 *  - **Discovery** keeps the id cursor. It is the right tool for finding
 *    rows that did not exist before, and it cannot skip one.
 *  - **Refresh** re-reads every game near its kickoff, cursor or no
 *    cursor, because that is the window in which a row changes. It is
 *    bounded by the window rather than by what we have already seen,
 *    which is the whole point.
 *
 * Re-reading is close to free: `Mirror::write` compares a digest and
 * writes nothing when a row still says what it said last time.
 */
class SyncEvents implements ShouldQueue
{
    use Queueable, SyncsFromEdgeLab;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * How far either side of now the refresh pass looks.
     *
     * Back far enough to cover a long weekend of missed runs and a result
     * that arrives late; forward far enough to catch a postponement
     * before anybody has published against the old kickoff.
     */
    private const REFRESH_LOOKBACK_DAYS = 7;

    private const REFRESH_LOOKAHEAD_DAYS = 3;

    /** A stop on both passes, so a runaway feed cannot pin a worker. */
    private const MAX_PAGES = 25;

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
            $observedAt = now();

            $discovered = $this->discover($client, $mirror, $run->cursor_before, $observedAt);
            $refreshed = $this->refresh($client, $mirror, $observedAt);

            return [
                'fetched' => $discovered['fetched'] + $refreshed['fetched'],
                'written' => $discovered['written'] + $refreshed['written'],
                'pages' => $discovered['pages'] + $refreshed['pages'],
                // The discovery cursor alone. The refresh pass walks the
                // same ids every run by design, and letting it move the
                // stored cursor would drag it backwards.
                'cursor' => $discovered['cursor'],
            ];
        });
    }

    /**
     * Games we have never seen, by id.
     *
     * @return array{fetched:int, written:int, cursor:?int, pages:int}
     */
    private function discover(EdgeLabClient $client, Mirror $mirror, ?int $cursor, Carbon $observedAt): array
    {
        $fetched = 0;
        $written = 0;
        $pages = 0;

        do {
            $page = $client->events($cursor);
            $pages++;
            $fetched += $page->count();

            foreach ($page->rows as $row) {
                $written += $mirror->write(VlEvent::class, $row, $this->columns($row), $observedAt) ? 1 : 0;

                // From the rows, not the meta. See SyncBoard.
                $cursor = max((int) $cursor, (int) $row['id']);
            }
        } while ($page->hasMore() && $pages < self::MAX_PAGES);

        return ['fetched' => $fetched, 'written' => $written, 'cursor' => $cursor, 'pages' => $pages];
    }

    /**
     * Games close enough to kickoff to still be changing.
     *
     * Paged by id within the window, starting from nothing each run: the
     * ids are stable, so a full walk of a bounded window cannot skip a
     * row and cannot loop.
     *
     * @return array{fetched:int, written:int, pages:int}
     */
    private function refresh(EdgeLabClient $client, Mirror $mirror, Carbon $observedAt): array
    {
        $window = [
            'from' => $observedAt->copy()->subDays(self::REFRESH_LOOKBACK_DAYS),
            'to' => $observedAt->copy()->addDays(self::REFRESH_LOOKAHEAD_DAYS),
        ];

        $cursor = null;
        $fetched = 0;
        $written = 0;
        $pages = 0;

        do {
            $page = $client->events($cursor, null, $window);
            $pages++;
            $fetched += $page->count();

            foreach ($page->rows as $row) {
                $written += $mirror->write(VlEvent::class, $row, $this->columns($row), $observedAt) ? 1 : 0;

                $cursor = max((int) $cursor, (int) $row['id']);
            }
        } while ($page->hasMore() && $pages < self::MAX_PAGES);

        return ['fetched' => $fetched, 'written' => $written, 'pages' => $pages];
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
