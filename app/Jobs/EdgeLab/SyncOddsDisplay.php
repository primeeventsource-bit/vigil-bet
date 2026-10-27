<?php

declare(strict_types=1);

namespace App\Jobs\EdgeLab;

use App\Models\Book;
use App\Models\SyncRun;
use App\Models\VlOddsDisplay;
use App\Services\EdgeLab\EdgeLabClient;
use App\Services\EdgeLab\Mirror;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Fills the display mirror with the prices the product shows.
 *
 * This feed was named in the config and the staleness table from the
 * start and never existed, so `vl_odds_display` held nothing at all.
 * Five things read it as their only source of a price - closing line
 * value on the public record and on a customer's own bets, line
 * shopping, the props page and the line-move alerts - and every one of
 * them was quietly answering from an empty table. Nothing broke, which
 * is why it went unnoticed: an empty mirror and a quiet night look
 * identical from the outside.
 *
 * One event at a time, deliberately. The odds licence permits showing
 * prices inside a paid interface and forbids repackaging the feed, and
 * `EdgeLabClient` has no bulk odds method by design - a test asserts it.
 * This asks the events feed which games are near kickoff and then asks
 * for each game's prices on its own, which is the same access the game
 * page already makes.
 *
 * Three things keep the volume honest, because an append-only table of
 * every price from thirty-five books would be enormous and mostly noise:
 *
 *  - a window, so only games near kickoff are priced at all;
 *  - books a customer can actually bet at, by default - a price at a
 *    book nobody here can use is not a price the product can offer, and
 *    an arbitrage between two of them is not one anybody can take;
 *  - the digest, so a price that has not moved costs a read and no row.
 */
class SyncOddsDisplay implements ShouldQueue
{
    use Queueable, SyncsFromEdgeLab;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * Longer than a worker's default.
     *
     * A full slate is sixty games asked for one at a time, and the first
     * version of this job exhausted its attempts in production against
     * exactly that. The per-game work is now one read and one write, but
     * the ceiling stays generous: a Saturday of college football is a
     * real shape this has to survive, and a timeout here means the
     * prices a customer is shown simply stop moving.
     */
    public int $timeout = 600;

    /** The columns that identify one selection at one book. */
    private const SELECTION_KEY = [
        'edge_lab_event_id', 'book', 'market', 'market_key', 'side', 'player_name', 'line',
    ];

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('edgelab-sync:odds'))->expireAfter(900)];
    }

    protected function feed(): string
    {
        return 'odds';
    }

    public function handle(EdgeLabClient $client): void
    {
        $this->tracked($client, function (SyncRun $run) use ($client): array {
            $observedAt = now();
            $books = $this->booksWorthMirroring();

            $fetched = 0;
            $written = 0;
            $events = 0;

            foreach ($this->eventsNearKickoff($client) as $eventId) {
                $odds = $client->odds($eventId);
                $events++;

                $quotes = (array) ($odds['quotes'] ?? []);

                if (config('edgelab.odds_mirror.include_props')) {
                    $quotes = array_merge($quotes, (array) ($odds['props'] ?? []));
                }

                $keep = array_values(array_filter(
                    $quotes,
                    fn (array $quote): bool => $this->worthKeeping($quote, $books),
                ));

                $fetched += count($keep);
                $written += $this->mirrorEvent($eventId, $keep, $observedAt);
            }

            return [
                'fetched' => $fetched,
                'written' => $written,
                'pages' => $events,
                // Not a cursor feed. Which games need a price is decided
                // by the clock, and a game whose price moved has no new
                // id to be found by.
                'cursor' => null,
            ];
        });
    }

    /**
     * Mirror one game's prices: one read, one write, whatever the slate.
     *
     * This began as a digest check per quote, which is the obvious shape
     * and was wrong by two orders of magnitude. Thirty-two games at a
     * hundred and thirty quotes each is four thousand round trips a run,
     * every ten minutes, against a database in a different region from
     * the application - the job exhausted its attempts in production on
     * the first slate it met.
     *
     * So the digests for a game come back in one query and are compared
     * in memory, and what changed goes in as one insert. An append-only
     * table reads newest-first, so the first row seen for a key is the
     * one to compare against.
     *
     * @param  array<int, array<string, mixed>>  $quotes
     */
    private function mirrorEvent(int $eventId, array $quotes, Carbon $observedAt): int
    {
        if ($quotes === []) {
            return 0;
        }

        $held = [];

        foreach (
            VlOddsDisplay::query()
                ->where('edge_lab_event_id', $eventId)
                ->orderByDesc('id')
                ->get([...self::SELECTION_KEY, 'digest']) as $row
        ) {
            // Newest first, so the first sighting of a key is the one we
            // are still holding. Later rows are its history.
            $held[$this->selectionKey($row->toArray())] ??= $row->digest;
        }

        $fresh = [];

        foreach ($quotes as $quote) {
            $columns = $this->columns($eventId, $quote);
            $digest = Mirror::digest($quote);

            if (($held[$this->selectionKey($columns)] ?? null) === $digest) {
                continue;
            }

            $fresh[] = $columns + ['digest' => $digest, 'observed_at' => $observedAt];
        }

        if ($fresh !== []) {
            VlOddsDisplay::insert($fresh);
        }

        return count($fresh);
    }

    /**
     * One selection at one book, as a string that can be compared.
     *
     * Null is a real value in this key - a moneyline has no line, only a
     * prop has a player - so it is spelled rather than left to collapse
     * into an empty string beside a genuine one.
     *
     * @param  array<string, mixed>  $row
     */
    private function selectionKey(array $row): string
    {
        $parts = [];

        foreach (self::SELECTION_KEY as $column) {
            $parts[] = ($row[$column] ?? null) === null ? "\0" : (string) $row[$column];
        }

        return implode('|', $parts);
    }

    /**
     * The games close enough to kickoff to be worth a price.
     *
     * Back a little as well as forward: closing line value is measured
     * from the last quote before kickoff, so a game that started while
     * the last run was working still needs one more look.
     *
     * @return array<int, int>
     */
    private function eventsNearKickoff(EdgeLabClient $client): array
    {
        $window = [
            'from' => now()->subHours((int) config('edgelab.odds_mirror.hours_back')),
            'to' => now()->addHours((int) config('edgelab.odds_mirror.hours_ahead')),
        ];

        $ids = [];
        $cursor = null;
        $max = (int) config('edgelab.odds_mirror.max_events');

        do {
            $page = $client->events($cursor, null, $window);

            foreach ($page->rows as $row) {
                $ids[] = (int) $row['id'];
                $cursor = max((int) $cursor, (int) $row['id']);
            }
        } while ($page->hasMore() && count($ids) < $max);

        // A ceiling, so a Saturday of college football cannot turn one
        // tick into a job that runs until a worker kills it.
        return array_slice($ids, 0, $max);
    }

    /**
     * The books whose prices are worth keeping, or null for all of them.
     *
     * @return Collection<int, string>|null
     */
    private function booksWorthMirroring(): ?Collection
    {
        if (! config('edgelab.odds_mirror.bettable_books_only')) {
            return null;
        }

        return Book::query()->bettable()->pluck('key');
    }

    /**
     * @param  array<string, mixed>  $quote
     * @param  Collection<int, string>|null  $books
     */
    private function worthKeeping(array $quote, ?Collection $books): bool
    {
        // The column is not nullable, and a quote with no price is not a
        // price - it is a book that has taken the market down.
        if (($quote['price_american'] ?? null) === null) {
            return false;
        }

        return $books === null || $books->contains($quote['book'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private function columns(int $eventId, array $quote): array
    {
        return [
            'edge_lab_event_id' => $eventId,
            'book' => $quote['book'] ?? null,
            'book_title' => $quote['book_title'] ?? null,
            'market' => $quote['market'] ?? null,
            'market_key' => $quote['market_key'] ?? null,
            'side' => $quote['side'] ?? null,
            'player_name' => $quote['player_name'] ?? null,
            'line' => $quote['line'] ?? null,
            'price_american' => (int) $quote['price_american'],
            'price_decimal' => $quote['price_decimal'] ?? null,
            // The engine's own pull time, not ours. Closing line value
            // asks whether a quote was taken before kickoff, and the
            // honest answer is about when the book said it.
            'quoted_at' => isset($quote['pulled_at']) ? Carbon::parse((string) $quote['pulled_at']) : null,
        ];
    }
}
