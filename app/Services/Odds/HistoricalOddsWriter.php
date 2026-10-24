<?php

declare(strict_types=1);

namespace App\Services\Odds;

use App\Data\EventOdds;
use App\Data\HistoricalOddsSnapshot;
use App\Modeling\Odds\OddsConverter;
use App\Models\Book;
use App\Models\Event;
use App\Models\OddsSnapshot;
use App\Services\Teams\TeamResolver;
use Illuminate\Support\Facades\DB;

/**
 * Writes a past board into the same append-only snapshot table as a live
 * pull, stamped with the moment it was actually true.
 *
 * Three things it deliberately does differently from OddsSnapshotWriter:
 *
 *  - `pulled_at` is the snapshot's own timestamp, not now. A quote is a
 *    fact about when it was quoted, and stamping last March with today is
 *    what would turn a backfill into a board full of fresh prices.
 *  - it never creates an event from the feed. Live pulls do, because a
 *    price for a game the schedule has not caught up with is still worth
 *    keeping. History is the other way round: the schedule is already
 *    there, and a game it does not know about is a matching failure worth
 *    counting rather than a fixture worth inventing.
 *  - rows are marked `historical_api`, so a closing line recovered from
 *    the archive can be told apart from one we watched happen.
 */
class HistoricalOddsWriter
{
    public const PROVENANCE = 'historical_api';

    public const SOURCE = 'the_odds_api_historical';

    /** @var array<string, int> */
    private array $bookCache = [];

    public function __construct(private readonly TeamResolver $teams) {}

    /**
     * @return array{snapshots:int, events_matched:int, events_unmatched:int}
     */
    public function write(HistoricalOddsSnapshot $snapshot): array
    {
        $pulledAt = $snapshot->timestamp ?? $snapshot->requestedAt;
        $written = 0;
        $matched = 0;
        $unmatched = 0;

        foreach ($snapshot->events as $eventOdds) {
            $event = $this->resolveEvent($eventOdds);

            if ($event === null) {
                $unmatched++;

                continue;
            }

            $matched++;
            $rows = [];

            foreach ($eventOdds->quotes as $quote) {
                if (! OddsConverter::isValidAmerican($quote->priceAmerican)) {
                    continue;
                }

                $rows[] = [
                    'event_id' => $event->id,
                    'book_id' => $this->bookId($quote->bookKey, $quote->bookTitle),
                    'market' => $quote->market->value,
                    'market_key' => null,
                    'side' => $quote->side->value,
                    'selection_team_id' => match ($quote->side->value) {
                        'home' => $event->home_team_id,
                        'away' => $event->away_team_id,
                        default => null,
                    },
                    'line' => $quote->line,
                    'price_american' => $quote->priceAmerican,
                    'price_decimal' => OddsConverter::americanToDecimal($quote->priceAmerican),
                    'api_last_update_at' => $quote->lastUpdateAt,
                    'pulled_at' => $pulledAt,
                    'provenance' => self::PROVENANCE,
                    'created_at' => now(),
                ];
            }

            if ($rows === []) {
                continue;
            }

            DB::transaction(function () use ($rows): void {
                foreach (array_chunk($rows, 500) as $chunk) {
                    OddsSnapshot::insert($chunk);
                }
            });

            $written += count($rows);
        }

        return [
            'snapshots' => $written,
            'events_matched' => $matched,
            'events_unmatched' => $unmatched,
        ];
    }

    /**
     * Find the game these prices belong to, and never invent one.
     *
     * The id is tried first and usually misses on a backfill, because a
     * season older than this app has events that came from the schedule
     * source and were never touched by a live odds pull. So the fallback
     * is the same teams-and-kickoff match the live writer uses, and a
     * match stamps the id on so the next request through this window
     * resolves on the first try.
     *
     * Where the live writer would create the event, this counts it as
     * unmatched. A live price for a game the schedule has not caught up
     * with is worth keeping; a historical one is a matching failure, and
     * an invented event would hide it and then poison the backtest it was
     * backfilled for.
     */
    private function resolveEvent(EventOdds $odds): ?Event
    {
        $existing = Event::query()
            ->where('odds_api_event_id', $odds->oddsApiEventId)
            ->first();

        if ($existing) {
            return $existing;
        }

        $home = $this->teams->resolve($odds->sport, $odds->homeTeamName, self::SOURCE);
        $away = $this->teams->resolve($odds->sport, $odds->awayTeamName, self::SOURCE);

        if ($home === null || $away === null) {
            return null;
        }

        $event = Event::query()
            ->where('sport', $odds->sport->value)
            ->where('home_team_id', $home->id)
            ->where('away_team_id', $away->id)
            ->whereBetween('starts_at', [
                $odds->commenceAt->subHours(24),
                $odds->commenceAt->addHours(24),
            ])
            ->first();

        if ($event !== null && $event->odds_api_event_id === null) {
            $event->forceFill(['odds_api_event_id' => $odds->oddsApiEventId])->save();
        }

        return $event;
    }

    private function bookId(string $key, string $title): int
    {
        if (isset($this->bookCache[$key])) {
            return $this->bookCache[$key];
        }

        $book = Book::query()->firstOrCreate(
            ['key' => $key],
            ['title' => $title, 'region' => 'us', 'is_tracked' => true]
        );

        return $this->bookCache[$key] = $book->id;
    }
}
