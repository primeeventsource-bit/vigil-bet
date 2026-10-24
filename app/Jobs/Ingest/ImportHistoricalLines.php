<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Data\HistoricalLine;
use App\Enums\Market;
use App\Enums\Side;
use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Modeling\Odds\OddsConverter;
use App\Models\Book;
use App\Models\EventExternalId;
use App\Models\JobRun;
use App\Models\OddsSnapshot;
use App\Services\Ingestion\ProvidesHistoricalLines;
use App\Services\Ingestion\SourceRegistry;
use App\Services\Ops\JobRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Imports closing lines for seasons that happened before this app existed.
 *
 * These rows are written under a clearly separate book so they can never be
 * mistaken for our own snapshots: the book is not tracked for line shopping,
 * and the UI labels anything derived from it as a historical closing line.
 * Without this, a backtest has no market to be measured against, and an
 * unmeasured model is exactly what this app refuses to trust.
 */
class ImportHistoricalLines implements ShouldQueue
{
    use Queueable, TracksJobRun;

    public const BOOK_KEY = 'historical_close';

    public int $timeout = 1800;

    public function __construct(
        public readonly Sport $sport,
        public readonly int $season,
        public readonly TriggerSource $triggeredBy = TriggerSource::Manual,
    ) {}

    public function handle(SourceRegistry $registry, JobRunRecorder $recorder): void
    {
        $this->tracked(
            "backfill:lines:{$this->sport->value}",
            function (JobRun $run) use ($registry, $recorder): array {
                $source = $registry->forRole($this->sport, 'schedules');

                if (! $source instanceof ProvidesHistoricalLines) {
                    $recorder->skip($run, sprintf(
                        'No free historical line source for %s. CLV and the market benchmark for this sport start from the day snapshots began.',
                        $this->sport->value,
                    ));

                    return [];
                }

                $source->withJobRun($run->id);
                $book = $this->book();
                $written = 0;
                $seen = 0;
                $unmatched = 0;

                foreach ($source->historicalLines($this->season) as $line) {
                    $seen++;
                    $event = $this->eventFor($source->key(), $line);

                    if ($event === null) {
                        $unmatched++;

                        continue;
                    }

                    $written += $this->writeRows($event, $book->id, $line);
                }

                return [
                    'records_in' => $seen,
                    'records_out' => $written,
                    'unmatched_events' => $unmatched,
                    'season' => $this->season,
                    'provenance' => 'historical dataset, not our own snapshots',
                ];
            },
            $this->sport,
            ['season' => $this->season],
        );
    }

    /** @return object{id:int, starts_at:mixed}|null */
    private function eventFor(string $source, HistoricalLine $line): ?object
    {
        $eventId = EventExternalId::query()
            ->where('source', $source)
            ->where('external_id', $line->sourceEventId)
            ->where('sport', $this->sport->value)
            ->value('event_id');

        if ($eventId === null) {
            return null;
        }

        return DB::table('events')->select('id', 'starts_at')->find($eventId);
    }

    private function writeRows(object $event, int $bookId, HistoricalLine $line): int
    {
        // Stamped just before kickoff: these are closing prices, and the
        // append-only history must still read in chronological order.
        $pulledAt = Carbon::parse($event->starts_at)->subMinute();

        $rows = [];

        $add = function (Market $market, Side $side, ?float $lineValue, ?int $price) use (&$rows, $event, $bookId, $pulledAt): void {
            if ($price === null || ! OddsConverter::isValidAmerican($price)) {
                return;
            }

            $rows[] = [
                'event_id' => $event->id,
                'book_id' => $bookId,
                'market' => $market->value,
                'side' => $side->value,
                'line' => $lineValue,
                'price_american' => $price,
                'price_decimal' => OddsConverter::americanToDecimal($price),
                'pulled_at' => $pulledAt,
                'created_at' => now(),
            ];
        };

        $add(Market::Moneyline, Side::Home, null, $line->homeMoneylineAmerican);
        $add(Market::Moneyline, Side::Away, null, $line->awayMoneylineAmerican);

        if ($line->homeSpread !== null) {
            $add(Market::Spread, Side::Home, $line->homeSpread, $line->homeSpreadOddsAmerican ?? -110);
            $add(Market::Spread, Side::Away, -$line->homeSpread, $line->awaySpreadOddsAmerican ?? -110);
        }

        if ($line->totalLine !== null) {
            $add(Market::Total, Side::Over, $line->totalLine, $line->overOddsAmerican ?? -110);
            $add(Market::Total, Side::Under, $line->totalLine, $line->underOddsAmerican ?? -110);
        }

        if ($rows === []) {
            return 0;
        }

        $existing = OddsSnapshot::query()
            ->where('event_id', $event->id)
            ->where('book_id', $bookId)
            ->exists();

        if ($existing) {
            return 0;
        }

        OddsSnapshot::insert($rows);

        return count($rows);
    }

    private function book(): Book
    {
        return Book::query()->firstOrCreate(
            ['key' => self::BOOK_KEY],
            [
                'title' => 'Historical closing line',
                'region' => 'historical',
                'is_tracked' => false,
                'display_order' => 999,
            ]
        );
    }
}
