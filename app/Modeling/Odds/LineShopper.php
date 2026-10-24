<?php

declare(strict_types=1);

namespace App\Modeling\Odds;

use App\Data\ShoppedLine;
use App\Enums\Market;
use App\Enums\Side;
use App\Models\Book;
use App\Models\Event;
use App\Models\OddsSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Collects the current price at every book for one side, and picks out the
 * one that matters: the price at the book the bet will be placed at.
 *
 * Only the latest quote from each book counts, and only while it is fresh.
 * A stale price is not a price - acting on one is how a phantom edge turns
 * into a rejected bet slip.
 */
class LineShopper
{
    public function shop(
        Event $event,
        Market $market,
        Side $side,
        ?float $line = null,
        ?Carbon $asOf = null,
        ?int $maxAgeMinutes = null,
        ?string $pricingBookKey = null,
    ): ShoppedLine {
        $asOf ??= now();
        $maxAgeMinutes ??= (int) config('edge.value.max_quote_age_minutes');
        $pricingBookKey ??= (string) config('edge.pricing_book');

        $snapshots = OddsSnapshot::query()
            ->where('event_id', $event->id)
            ->where('market', $market->value)
            ->where('side', $side->value)
            ->where('pulled_at', '<=', $asOf)
            ->where('pulled_at', '>=', $asOf->copy()->subMinutes($maxAgeMinutes))
            ->when($line !== null, fn ($q) => $q->where('line', $line))
            ->orderBy('pulled_at')
            ->get(['book_id', 'line', 'price_american', 'pulled_at']);

        if ($snapshots->isEmpty()) {
            return $this->empty($line);
        }

        $books = Book::query()
            ->whereIn('id', $snapshots->pluck('book_id')->unique())
            ->get(['id', 'key', 'title', 'is_bettable', 'is_tracked'])
            ->keyBy('id');

        $latest = [];

        foreach ($snapshots as $snapshot) {
            $book = $books[$snapshot->book_id] ?? null;

            // The historical closing line bucket is not a real book and must
            // never be mistaken for a price on offer now.
            if ($book === null || ! $book->is_tracked) {
                continue;
            }

            // Last quote wins; the collection is ordered oldest first.
            $latest[$book->key] = [
                'book_id' => $book->id,
                'book' => $book->title,
                'price' => $snapshot->price_american,
                'line' => $snapshot->line,
                'pulled_at' => $snapshot->pulled_at->toIso8601String(),
                'bettable' => (bool) $book->is_bettable,
                'pricing' => $book->key === $pricingBookKey,
            ];
        }

        return $this->assemble($latest, $pricingBookKey, $line);
    }

    /**
     * Shop every side of every market for a whole slate, in one query.
     *
     * The board asks for forty games across three markets and two sides.
     * Done one call at a time that is 240 queries, each scanning an
     * odds_snapshots table with a couple of hundred thousand rows in it,
     * and on production that took sixteen seconds and timed the dashboard
     * out at the gateway. The work is identical; only the number of round
     * trips changes.
     *
     * The grouping is done in PHP rather than with a window function
     * because the same code has to run on SQLite locally and Postgres in
     * production, and "last row wins per book" is cheap once the rows are
     * ordered.
     *
     * @param  iterable<int, Event>  $events
     * @param  array<int, Market>  $markets
     * @return array<string, ShoppedLine> keyed "{eventId}|{market}|{side}"
     */
    public function shopMany(
        iterable $events,
        array $markets,
        ?Carbon $asOf = null,
        ?int $maxAgeMinutes = null,
        ?string $pricingBookKey = null,
    ): array {
        $asOf ??= now();
        $maxAgeMinutes ??= (int) config('edge.value.max_quote_age_minutes');
        $pricingBookKey ??= (string) config('edge.pricing_book');

        $eventIds = [];

        foreach ($events as $event) {
            $eventIds[] = $event->id;
        }

        if ($eventIds === [] || $markets === []) {
            return [];
        }

        $marketValues = array_map(static fn (Market $m) => $m->value, $markets);
        $from = $asOf->copy()->subMinutes($maxAgeMinutes);

        // Only the newest quote per book matters, so only the newest quote
        // per book is fetched. Reading the whole window and discarding the
        // rest means moving 38,820 rows to use 4,002 of them, and paying
        // Eloquent to hydrate a model for each one.
        //
        // Grouped on pulled_at rather than on max(id), because ids are not
        // a reliable proxy for recency here: the line backfill writes
        // historical closing prices long after the live pulls they predate,
        // so the highest id in a group can be the oldest quote in it.
        $latestPerBook = DB::table('odds_snapshots')
            ->selectRaw('event_id, market, side, book_id, MAX(pulled_at) as newest_at')
            ->whereIn('event_id', $eventIds)
            ->whereIn('market', $marketValues)
            ->where('pulled_at', '<=', $asOf)
            ->where('pulled_at', '>=', $from)
            ->groupBy('event_id', 'market', 'side', 'book_id');

        $snapshots = DB::table('odds_snapshots as s')
            ->joinSub($latestPerBook, 'newest', function ($join) {
                $join->on('s.event_id', '=', 'newest.event_id')
                    ->on('s.market', '=', 'newest.market')
                    ->on('s.side', '=', 'newest.side')
                    ->on('s.book_id', '=', 'newest.book_id')
                    ->on('s.pulled_at', '=', 'newest.newest_at');
            })
            ->whereIn('s.event_id', $eventIds)
            ->whereIn('s.market', $marketValues)
            ->orderBy('s.pulled_at')
            ->orderBy('s.id')
            ->get(['s.event_id', 's.market', 's.side', 's.book_id', 's.line', 's.price_american', 's.pulled_at']);

        if ($snapshots->isEmpty()) {
            return [];
        }

        $books = Book::query()
            ->whereIn('id', $snapshots->pluck('book_id')->unique())
            ->get(['id', 'key', 'title', 'is_bettable', 'is_tracked'])
            ->keyBy('id');

        /** @var array<string, array<string, array<string, mixed>>> $latest */
        $latest = [];

        foreach ($snapshots as $snapshot) {
            $book = $books[$snapshot->book_id] ?? null;

            if ($book === null || ! $book->is_tracked) {
                continue;
            }

            $market = (string) $snapshot->market;
            $side = (string) $snapshot->side;

            // Plain rows rather than models, so the timestamp arrives as a
            // string and the price as whatever the driver returned.
            $latest[$snapshot->event_id.'|'.$market.'|'.$side][$book->key] = [
                'book_id' => $book->id,
                'book' => $book->title,
                'price' => (int) $snapshot->price_american,
                'line' => $snapshot->line === null ? null : (float) $snapshot->line,
                'pulled_at' => Carbon::parse($snapshot->pulled_at)->toIso8601String(),
                'bettable' => (bool) $book->is_bettable,
                'pricing' => $book->key === $pricingBookKey,
            ];
        }

        $shopped = [];

        foreach ($latest as $key => $quotes) {
            $shopped[$key] = $this->assemble($quotes, $pricingBookKey, null);
        }

        return $shopped;
    }

    /**
     * Turn the latest quote per book into a ShoppedLine.
     *
     * Shared by both paths on purpose: one game at a time and a whole
     * slate at once have to agree exactly, and the surest way to keep two
     * code paths agreeing is to give them one body.
     *
     * @param  array<string, array<string, mixed>>  $latest
     */
    private function assemble(array $latest, string $pricingBookKey, ?float $line): ShoppedLine
    {
        if ($latest === []) {
            return $this->empty($line);
        }

        $pricing = $latest[$pricingBookKey] ?? null;
        $best = $this->best($latest);

        return new ShoppedLine(
            quotes: $latest,
            pricingBookId: $pricing['book_id'] ?? null,
            pricingBookPriceAmerican: $pricing['price'] ?? null,
            pricingBookTitle: $pricing['book'] ?? null,
            bestMarketBookId: $best['book_id'] ?? null,
            bestMarketPriceAmerican: $best['price'] ?? null,
            bestMarketBookTitle: $best['book'] ?? null,
            line: $pricing['line'] ?? $line ?? ($best['line'] ?? null),
            bookCount: count($latest),
            pricePulledAt: $pricing['pulled_at'] ?? null,
        );
    }

    /**
     * Best means the biggest payout, which is the highest decimal odds, not
     * the biggest American number: -105 beats -110, and +100 beats both.
     *
     * @param  array<string, array{price:int}>  $quotes
     * @return array<string, mixed>
     */
    private function best(array $quotes): array
    {
        $best = [];
        $bestDecimal = 0.0;

        foreach ($quotes as $quote) {
            if (! OddsConverter::isValidAmerican($quote['price'])) {
                continue;
            }

            $decimal = OddsConverter::americanToDecimal($quote['price']);

            if ($decimal > $bestDecimal) {
                $bestDecimal = $decimal;
                $best = $quote;
            }
        }

        return $best;
    }

    private function empty(?float $line): ShoppedLine
    {
        return new ShoppedLine([], null, null, null, null, null, null, $line, 0);
    }
}
