<?php

declare(strict_types=1);

namespace App\Services\Odds;

use App\Data\EventProps;
use App\Modeling\Odds\OddsConverter;
use App\Models\Book;
use App\Models\Event;
use App\Models\OddsSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a props pull into append-only snapshot rows.
 *
 * The same table and the same rules as OddsSnapshotWriter, with two
 * differences that matter:
 *
 *  - the row carries a `market_key` and leaves `market` null, so no reader
 *    that feeds the model can pick it up;
 *  - an unmatched event is never created from the feed. Props are only ever
 *    asked for by the id of a game already on the board, so an id that does
 *    not resolve is a bug rather than a new fixture, and inventing an event
 *    with no teams in it would hide that.
 */
class PropSnapshotWriter
{
    public const PROVENANCE = 'live_pull';

    /** @var array<string, int> */
    private array $bookCache = [];

    /**
     * @return array{snapshots:int, markets:int}
     */
    public function write(EventProps $props, ?Carbon $pulledAt = null): array
    {
        $pulledAt ??= now();

        // A payload carrying a different game than the one asked for means
        // the prices belong somewhere else, and attaching them here would
        // be worse than having none.
        if (! $props->isForRequestedEvent()) {
            return ['snapshots' => 0, 'markets' => 0];
        }

        $event = Event::query()
            ->where('odds_api_event_id', $props->oddsApiEventId)
            ->first();

        if ($event === null) {
            return ['snapshots' => 0, 'markets' => 0];
        }

        $rows = [];

        foreach ($props->quotes as $quote) {
            if (! OddsConverter::isValidAmerican($quote->priceAmerican)) {
                continue;
            }

            $rows[] = [
                'event_id' => $event->id,
                'book_id' => $this->bookId($quote->bookKey, $quote->bookTitle),
                'market' => null,
                'market_key' => $quote->marketKey,
                'side' => $quote->side->value,
                'selection_team_id' => null,
                'player_name' => $quote->playerName,
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
            return ['snapshots' => 0, 'markets' => 0];
        }

        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                OddsSnapshot::insert($chunk);
            }
        });

        return ['snapshots' => count($rows), 'markets' => count($props->marketKeys())];
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
