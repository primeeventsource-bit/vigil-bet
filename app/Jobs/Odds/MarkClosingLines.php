<?php

declare(strict_types=1);

namespace App\Jobs\Odds;

use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\Event;
use App\Models\EventLineMarker;
use App\Models\OddsSnapshot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Freezes the opening and closing quote for every event that has started.
 *
 * The closing line is the last price available before kickoff. It is the
 * benchmark for CLV, which is the honest measure of whether a bet was good
 * at the moment it was made, independent of whether it won.
 */
class MarkClosingLines implements ShouldQueue
{
    use Queueable, TracksJobRun;

    public int $tries = 2;

    public function __construct(
        public readonly int $lookbackHours = 48,
        public readonly int $maxEvents = 500,
        public readonly TriggerSource $triggeredBy = TriggerSource::Schedule,
    ) {}

    public function handle(): void
    {
        $this->tracked('odds:close', function (): array {
            // A fixed lookback window loses data permanently: if the
            // scheduler is down longer than the window - a deploy, an
            // outage, a worker that died quietly - those games fall out the
            // back and their closing lines are never computed. Closing lines
            // are the entire basis of CLV, which is what proves the model, so
            // losing them silently is the worst failure in this pipeline.
            //
            // Instead: anything started and never marked, however old, plus
            // anything recent, which may have collected later snapshots since
            // it was last marked.
            $events = Event::query()
                ->where('starts_at', '<', now())
                ->whereExists(fn ($q) => $q->selectRaw('1')
                    ->from('odds_snapshots')
                    ->whereColumn('odds_snapshots.event_id', 'events.id'))
                ->where(fn ($q) => $q
                    ->whereNotExists(fn ($inner) => $inner->selectRaw('1')
                        ->from('event_line_markers')
                        ->whereColumn('event_line_markers.event_id', 'events.id'))
                    ->orWhere('starts_at', '>=', now()->subHours($this->lookbackHours)))
                ->orderBy('starts_at')
                ->limit($this->maxEvents)
                ->pluck('starts_at', 'id');

            $markers = 0;

            foreach ($events as $eventId => $startsAt) {
                $markers += $this->markEvent((int) $eventId, $startsAt);
            }

            return ['records_in' => $events->count(), 'records_out' => $markers];
        });
    }

    private function markEvent(int $eventId, mixed $startsAt): int
    {
        // Core markets only. A closing line is the last price on the game's
        // own outcome; a player prop is a different question and has no
        // `market` to group by.
        $snapshots = OddsSnapshot::query()
            ->where('event_id', $eventId)
            ->coreMarkets()
            ->orderBy('pulled_at')
            ->orderBy('id')
            ->get(['id', 'book_id', 'market', 'side', 'line', 'price_american', 'pulled_at']);

        if ($snapshots->isEmpty()) {
            return 0;
        }

        $groups = [];

        foreach ($snapshots as $snapshot) {
            $key = $snapshot->book_id.'|'.$snapshot->market->value.'|'.$snapshot->side->value;

            $groups[$key]['opening'] ??= $snapshot;

            if ($snapshot->pulled_at->lessThan($startsAt)) {
                $groups[$key]['closing'] = $snapshot;
            }
        }

        /*
         * One upsert for the whole event, not one per book and side.
         *
         * This used to loop updateOrCreate over every group, which is a
         * query each. A popular game is quoted by fifty books across three
         * markets and two sides, so marking one event cost roughly three
         * hundred round trips - and a run of five hundred events cost a
         * hundred and fifty thousand. The job exceeded the worker timeout,
         * was retried, exceeded it again and died on max attempts.
         *
         * The visible symptom was three years of history with almost no
         * closing lines: MLB had two marked games out of 3,433 and NCAAF
         * sixty-one out of 9,847, because only the small nightly window
         * ever finished. Closing lines are the entire basis of CLV, so the
         * models had nothing to be measured against and every verdict came
         * back `insufficient_data`.
         */
        $now = now();
        $rows = [];

        foreach ($groups as $key => $pair) {
            [$bookId, $market, $side] = explode('|', $key);

            $opening = $pair['opening'] ?? null;
            $closing = $pair['closing'] ?? null;

            $rows[] = [
                'event_id' => $eventId,
                'book_id' => (int) $bookId,
                'market' => $market,
                'side' => $side,
                'line_open' => $opening?->line,
                'price_open_american' => $opening?->price_american,
                'opened_at' => $opening?->pulled_at,
                'opening_snapshot_id' => $opening?->id,
                'line_close' => $closing?->line,
                'price_close_american' => $closing?->price_american,
                'closed_at' => $closing?->pulled_at,
                'closing_snapshot_id' => $closing?->id,
                'computed_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        /*
         * Chunked because an upsert is still one statement with one
         * placeholder per column per row, and a game quoted by every book
         * in the feed can exceed what the driver will accept in a single
         * statement.
         */
        foreach (array_chunk($rows, 200) as $chunk) {
            EventLineMarker::query()->upsert(
                $chunk,
                ['event_id', 'book_id', 'market', 'side'],
                [
                    'line_open', 'price_open_american', 'opened_at', 'opening_snapshot_id',
                    'line_close', 'price_close_american', 'closed_at', 'closing_snapshot_id',
                    'computed_at',
                ],
            );
        }

        return count($rows);
    }
}
