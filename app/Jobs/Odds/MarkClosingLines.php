<?php

declare(strict_types=1);

namespace App\Jobs\Odds;

use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\Event;
use App\Models\EventLineMarker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

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

    /**
     * Events per round trip.
     *
     * Small on purpose. Each chunk costs two statements whatever it
     * contains, so the only thing this number controls is how much the
     * database sorts at once - and the tail of this table is extreme
     * enough that a large chunk is a long statement waiting to happen.
     */
    private const CHUNK = 50;

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
                ->pluck('id');

            $markers = 0;

            foreach ($events->chunk(self::CHUNK) as $chunk) {
                $markers += $this->markEvents($chunk->all());
            }

            return ['records_in' => $events->count(), 'records_out' => $markers];
        });
    }

    /**
     * Mark a batch of events with two statements, whatever their size.
     *
     * This used to load every core-market snapshot for an event into
     * Eloquent and pick the first and last of each group in PHP. That is
     * fine for a typical game and ruinous for the tail: snapshots average
     * about sixteen hundred per event and the worst event in production
     * holds forty-seven thousand, so one game could hydrate tens of
     * thousands of models - with enum casts and date parsing on each - to
     * arrive at roughly three hundred rows.
     *
     * The symptom was a job that ran for over four hours, was closed by
     * the reaper at thirty minutes, retried, and died the same way. It
     * still made progress each time, which is what made it hard to see:
     * the backlog shrank, so the failures read as flakiness rather than as
     * one pathological event poisoning every run it landed in.
     *
     * Bounding the batch was never the fix. `maxEvents` bounds how many
     * events a run takes and nothing bounded the work inside one, so the
     * cost was set by the largest event in the batch however small the
     * batch was. Picking the first and last row per group is what a window
     * function is for, so the database does it and only the answer - one
     * row per book, market and side - is ever built in PHP.
     *
     * @param  array<int, int>  $eventIds
     */
    private function markEvents(array $eventIds): int
    {
        if ($eventIds === []) {
            return 0;
        }

        $openings = $this->edgeQuotes($eventIds, closing: false);
        $closings = $this->edgeQuotes($eventIds, closing: true);

        $now = now();
        $rows = [];

        /*
         * Keyed off the openings. A group with a closing quote and no
         * opening one cannot exist - the closing set is the subset of the
         * same rows filtered to before kickoff - so there is nothing to
         * union in, and iterating the openings keeps a group that is
         * entirely post-kickoff in the output with null closing columns,
         * which is the honest answer for a game nobody priced in time.
         */
        foreach ($openings as $key => $opening) {
            $closing = $closings[$key] ?? null;

            $rows[] = [
                'event_id' => (int) $opening->event_id,
                'book_id' => (int) $opening->book_id,
                'market' => $opening->market,
                'side' => $opening->side,
                'line_open' => $opening->line,
                'price_open_american' => $opening->price_american,
                'opened_at' => $opening->pulled_at,
                'opening_snapshot_id' => $opening->id,
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

    /**
     * One quote per event, book, market and side: the earliest, or the
     * latest before that event's own kickoff.
     *
     * The kickoff comes from a join rather than from a parameter, because
     * each event in the batch closes at a different moment - which is the
     * only reason this cannot be a single statement for both ends.
     *
     * Core markets only. A closing line is the last price on the game's
     * own outcome; a player prop is a different question and has no
     * `market` to group by. `whereNotNull('market')` is what
     * OddsSnapshot::coreMarkets() means, restated here because this runs
     * as a subquery rather than through the model.
     *
     * @param  array<int, int>  $eventIds
     * @return array<string, object>
     */
    private function edgeQuotes(array $eventIds, bool $closing): array
    {
        $order = $closing ? 'desc' : 'asc';

        $ranked = DB::table('odds_snapshots as o')
            ->select([
                'o.id', 'o.event_id', 'o.book_id', 'o.market', 'o.side',
                'o.line', 'o.price_american', 'o.pulled_at',
            ])
            ->selectRaw(
                "row_number() over (
                    partition by o.event_id, o.book_id, o.market, o.side
                    order by o.pulled_at {$order}, o.id {$order}
                ) as rn"
            )
            ->whereIn('o.event_id', $eventIds)
            ->whereNotNull('o.market');

        if ($closing) {
            // Strictly before kickoff. A quote stamped at or after it is
            // not a closing line, it is a live price.
            $ranked
                ->join('events as e', 'e.id', '=', 'o.event_id')
                ->whereColumn('o.pulled_at', '<', 'e.starts_at');
        }

        $quotes = [];

        foreach ($this->firstOfEachGroup($ranked)->get() as $row) {
            $quotes[$row->event_id.'|'.$row->book_id.'|'.$row->market.'|'.$row->side] = $row;
        }

        return $quotes;
    }

    /** @param  QueryBuilder  $ranked */
    private function firstOfEachGroup($ranked): QueryBuilder
    {
        return DB::query()->fromSub($ranked, 'ranked')->where('rn', 1);
    }
}
