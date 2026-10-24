<?php

declare(strict_types=1);

namespace App\Services\Odds;

use App\Data\EventOdds;
use App\Enums\EventStatus;
use App\Modeling\Odds\OddsConverter;
use App\Models\Book;
use App\Models\Event;
use App\Models\OddsSnapshot;
use App\Services\Teams\TeamResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a pull into append-only snapshot rows.
 *
 * Every quote is inserted; nothing is updated or de-duplicated away, because
 * the movement of a line over time is itself the data.
 */
class OddsSnapshotWriter
{
    public const SOURCE = 'the_odds_api';

    /** @var array<string, int> */
    private array $bookCache = [];

    public function __construct(private readonly TeamResolver $teams) {}

    /**
     * @param  array<int, EventOdds>  $events
     * @return array{snapshots:int, events_matched:int, events_unmatched:int}
     */
    public function write(array $events, ?Carbon $pulledAt = null): array
    {
        $pulledAt ??= now();
        $snapshots = 0;
        $matched = 0;
        $unmatched = 0;

        foreach ($events as $eventOdds) {
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
                    'created_at' => now(),
                ];
            }

            if ($rows !== []) {
                DB::transaction(function () use ($rows): void {
                    foreach (array_chunk($rows, 500) as $chunk) {
                        OddsSnapshot::insert($chunk);
                    }
                });

                $snapshots += count($rows);
            }
        }

        return [
            'snapshots' => $snapshots,
            'events_matched' => $matched,
            'events_unmatched' => $unmatched,
        ];
    }

    /**
     * Match the odds feed to a scheduled event. If both teams resolve but no
     * event exists yet, the event is created from the feed so prices are
     * never dropped on the floor; the schedule sync fills in the rest later.
     */
    private function resolveEvent(EventOdds $odds): ?Event
    {
        $existing = Event::query()
            ->where('odds_api_event_id', $odds->oddsApiEventId)
            ->first();

        if ($existing) {
            return $existing;
        }

        $home = $this->teams->resolve(
            $odds->sport,
            $odds->homeTeamName,
            self::SOURCE,
            context: ['odds_api_event_id' => $odds->oddsApiEventId, 'role' => 'home'],
        );

        $away = $this->teams->resolve(
            $odds->sport,
            $odds->awayTeamName,
            self::SOURCE,
            context: ['odds_api_event_id' => $odds->oddsApiEventId, 'role' => 'away'],
        );

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

        if ($event) {
            if ($event->odds_api_event_id === null) {
                $event->forceFill(['odds_api_event_id' => $odds->oddsApiEventId])->save();
            }

            return $event;
        }

        return Event::create([
            'sport' => $odds->sport->value,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'starts_at' => $odds->commenceAt,
            'status' => EventStatus::Scheduled->value,
            'odds_api_event_id' => $odds->oddsApiEventId,
        ]);
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
