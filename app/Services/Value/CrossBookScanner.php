<?php

declare(strict_types=1);

namespace App\Services\Value;

use App\Enums\EventStatus;
use App\Enums\Market;
use App\Enums\Side;
use App\Enums\Sport;
use App\Enums\VigMethod;
use App\Modeling\Math\Normal;
use App\Modeling\Odds\Arbitrage;
use App\Modeling\Odds\Middle;
use App\Modeling\Odds\OddsConverter;
use App\Modeling\Odds\VigRemover;
use App\Models\ArbitrageOpportunity;
use App\Models\Book;
use App\Models\Event;
use App\Models\MiddleOpportunity;
use App\Models\OddsSnapshot;
use App\Models\Prediction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reads the whole board at once and looks for the two things a single
 * price can never show: two books disagreeing enough to pay either way,
 * and two books disagreeing enough to leave a gap where both bets win.
 *
 * These are the bonus tools, and they are built with more suspicion than
 * the model is, because they are the two features of a betting app most
 * likely to be wrong in a way that looks right.
 *
 * Three rules, all of them about not believing the screen:
 *
 *  - A stale quote is not a quote. Nearly every arbitrage that shows up in
 *    a scan is one book that has moved and one that has not been polled
 *    since. The age of the older leg is recorded on every row, because it
 *    is the single best predictor of an opportunity being a ghost.
 *  - A leg at a book with no account is not a leg. Both ends have to be
 *    reachable, or the row is marked unplaceable and stays an observation.
 *  - A width is not an edge. A middle is reported with the hit rate it
 *    needs beside the hit rate the board itself implies, because a three
 *    point gap that lands 4% of the time and needs 4.8% is a losing bet
 *    that looks like a free one.
 */
class CrossBookScanner
{
    public function scan(?Sport $sport = null, int $horizonHours = 72, ?int $jobRunId = null): CrossBookResult
    {
        $now = now();
        $maxAge = (int) config('edge.value.max_quote_age_minutes');
        $reference = (int) config('edge.crossbook.reference_stake_cents');
        $minProfit = (float) config('edge.crossbook.min_arb_profit');
        $method = VigMethod::tryFrom((string) config('edge.value.vig_method')) ?? VigMethod::Multiplicative;

        $books = Book::query()->get(['id', 'key', 'title', 'is_bettable', 'is_tracked'])->keyBy('id');
        $arbitrages = 0;
        $middles = 0;

        foreach ($this->events($sport, $horizonHours) as $event) {
            $quotes = $this->quotes($event, $now, $maxAge, $books);

            foreach ($quotes as $marketValue => $byLine) {
                $market = Market::tryFrom((string) $marketValue);

                if ($market === null) {
                    continue;
                }

                if ($this->recordArbitrage($event, $market, $byLine, $now, $reference, $minProfit, $jobRunId)) {
                    $arbitrages++;
                }

                if ($market === Market::Moneyline) {
                    continue;
                }

                if ($this->recordMiddle($event, $market, $byLine, $now, $reference, $method, $jobRunId)) {
                    $middles++;
                }
            }
        }

        return new CrossBookResult($arbitrages, $middles, $now);
    }

    /** @return Collection<int, Event> */
    private function events(?Sport $sport, int $horizonHours): Collection
    {
        return Event::query()
            ->where('status', EventStatus::Scheduled)
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHours($horizonHours))
            ->when($sport, fn ($query) => $query->forSport($sport))
            ->with(['homeTeam:id,display_name', 'awayTeam:id,display_name'])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * The latest fresh quote from every book, filed under market and line.
     *
     * Filed by line because a price means nothing without the number it was
     * offered at: two books at -110 are the same bet at 44.5 each, and a
     * quite different one at 44.5 against 47.
     *
     * Indexed market, then side, then line, then book - and in that order
     * because the two sides of a spread are stored at opposite signs of the
     * same number. A home line of -3 and an away line of +3 are one market,
     * so anything that files both sides under a shared line key silently
     * stops pairing them, and spread arbitrage quietly becomes impossible
     * to find. Totals do not have the problem, which is exactly what makes
     * it easy to miss.
     *
     * @param  Collection<int, Book>  $books
     * @return array<string, array<string, array<string, array<string, array<string, mixed>>>>>
     */
    private function quotes(Event $event, Carbon $now, int $maxAge, Collection $books): array
    {
        // Core markets only. Two books disagreeing about a player's points
        // line is not an arbitrage on this game, and a prop row has no
        // `market` for the pairing below to key on.
        $snapshots = OddsSnapshot::query()
            ->where('event_id', $event->id)
            ->coreMarkets()
            ->where('pulled_at', '<=', $now)
            ->where('pulled_at', '>=', $now->copy()->subMinutes($maxAge))
            ->orderBy('pulled_at')
            ->get(['book_id', 'market', 'side', 'line', 'price_american', 'pulled_at']);

        $quotes = [];

        foreach ($snapshots as $snapshot) {
            $book = $books[$snapshot->book_id] ?? null;

            // The closing-line bucket is not a book and is never bettable.
            if ($book === null || ! $book->is_tracked) {
                continue;
            }

            if (! OddsConverter::isValidAmerican((int) $snapshot->price_american)) {
                continue;
            }

            $market = $snapshot->market instanceof Market ? $snapshot->market->value : (string) $snapshot->market;
            $side = $snapshot->side instanceof Side ? $snapshot->side->value : (string) $snapshot->side;
            // Ordered oldest first, so the last write to a slot is newest.
            $quotes[$market][$side][$this->lineKey(
                $snapshot->line === null ? null : (float) $snapshot->line
            )][$book->key] = [
                'book_id' => $book->id,
                'book_key' => $book->key,
                'book' => $book->title,
                'price_american' => (int) $snapshot->price_american,
                'price_decimal' => OddsConverter::americanToDecimal((int) $snapshot->price_american),
                'line' => $snapshot->line === null ? null : (float) $snapshot->line,
                'pulled_at' => $snapshot->pulled_at->toIso8601String(),
                'age_seconds' => (int) $snapshot->pulled_at->diffInSeconds($now, absolute: true),
                'bettable' => (bool) $book->is_bettable,
            ];
        }

        return $quotes;
    }

    /**
     * The best price for each side at one number, and whether the two
     * together pay more than certainty is worth.
     *
     * One row per event and market at most. A sweep that recorded every
     * pair of books would record the same disagreement a dozen times and
     * make the history unreadable.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $byMarket
     */
    private function recordArbitrage(
        Event $event,
        Market $market,
        array $byMarket,
        Carbon $now,
        int $referenceStake,
        float $minProfit,
        ?int $jobRunId,
    ): bool {
        [$sideA, $sideB] = $market->sides();
        $best = null;

        foreach ($byMarket[$sideA->value] ?? [] as $lineKey => $booksA) {
            $line = $lineKey === 'null' ? null : (float) $lineKey;

            // The other side of a spread is quoted at the opposite sign of
            // the same number; a total is quoted at the same number.
            $counterpart = $this->lineKey(
                $line === null ? null : $this->counterpartLine($market, $line)
            );

            $legs = [
                $sideA->value => $this->bestQuote($booksA),
                $sideB->value => $this->bestQuote($byMarket[$sideB->value][$counterpart] ?? []),
            ];

            if (in_array(null, $legs, true)) {
                continue;
            }

            $prices = array_map(static fn (array $leg) => $leg['price_american'], $legs);
            $sum = Arbitrage::impliedSum($prices);

            if ($sum >= 1.0) {
                continue;
            }

            $profit = Arbitrage::profitPerUnit($prices);

            if ($profit < $minProfit) {
                continue;
            }

            if ($best === null || $profit > $best['profit']) {
                $best = [
                    'line' => $line,
                    'legs' => $legs,
                    'prices' => $prices,
                    'sum' => $sum,
                    'profit' => $profit,
                ];
            }
        }

        if ($best === null) {
            return false;
        }

        $split = Arbitrage::stakeSplitCents($best['prices'], $referenceStake);

        ArbitrageOpportunity::create([
            'event_id' => $event->id,
            'sport' => $this->sportValue($event),
            'market' => $market->value,
            'line' => $best['line'],
            // Keyed by side while they were being compared; the side has to
            // travel onto the row, because a price with no side on it is
            // unreadable afterwards.
            'legs' => array_values(array_map(
                static fn (string $side, array $leg) => ['side' => $side] + $leg,
                array_keys($best['legs']),
                $best['legs'],
            )),
            'implied_sum' => $best['sum'],
            'profit_pct' => $best['profit'],
            'stake_split' => $split,
            'reference_stake_cents' => $referenceStake,
            'guaranteed_profit_cents' => Arbitrage::guaranteedProfitCents($best['prices'], $split),
            'placeable' => $this->allPlaceable($best['legs']),
            'oldest_quote_seconds' => $this->oldest($best['legs']),
            'job_run_id' => $jobRunId,
            'detected_at' => $now,
            'created_at' => $now,
        ]);

        return true;
    }

    /**
     * The gap worth having, judged on what the board says it is worth
     * rather than on how wide it is.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $byMarket
     */
    private function recordMiddle(
        Event $event,
        Market $market,
        array $byMarket,
        Carbon $now,
        int $referenceStake,
        VigMethod $method,
        ?int $jobRunId,
    ): bool {
        [$lowSide, $highSide] = $market->sides();
        $best = null;

        foreach ($byMarket[$lowSide->value] ?? [] as $lowLine => $lowBooks) {
            if ($lowLine === 'null') {
                continue;
            }

            foreach ($byMarket[$highSide->value] ?? [] as $highLine => $highBooks) {
                if ($highLine === 'null') {
                    continue;
                }

                $window = Middle::window($market, (float) $lowLine, (float) $highLine);

                if ($window === null) {
                    continue;
                }

                $low = $this->bestQuote($lowBooks);
                $high = $this->bestQuote($highBooks);

                if ($low === null || $high === null) {
                    continue;
                }

                $marketHit = $this->marketHitProbability(
                    $byMarket,
                    $market,
                    $method,
                    $lowSide,
                    (float) $lowLine,
                    $low['book_key'],
                    $highSide,
                    (float) $highLine,
                    $high['book_key'],
                );

                $candidate = [
                    'window' => $window,
                    'low' => $low,
                    'high' => $high,
                    'market_hit' => $marketHit,
                    'ev' => $marketHit === null
                        ? null
                        : Middle::expectedValuePerUnit($low['price_american'], $high['price_american'], $marketHit),
                    'outs' => Middle::outs($market, (float) $lowLine, (float) $highLine),
                ];

                if ($best === null || $this->betterMiddle($candidate, $best)) {
                    $best = $candidate;
                }
            }
        }

        if ($best === null) {
            return false;
        }

        $low = $best['low'];
        $high = $best['high'];
        $modelHit = $this->modelHitProbability($event, $market, $best['window']);

        MiddleOpportunity::create([
            'event_id' => $event->id,
            'sport' => $this->sportValue($event),
            'market' => $market->value,
            'leg_a' => ['side' => $lowSide->value] + $low,
            'leg_b' => ['side' => $highSide->value] + $high,
            'middle_low' => $best['window']['low'],
            'middle_high' => $best['window']['high'],
            'width' => $best['window']['high'] - $best['window']['low'],
            'outs' => $best['outs'],
            'implied_sum' => Middle::impliedSum($low['price_american'], $high['price_american']),
            'break_even_hit_prob' => Middle::breakEvenHitProbability($low['price_american'], $high['price_american']),
            'market_hit_prob' => $best['market_hit'],
            'model_hit_prob' => $modelHit,
            'net_cost_pct' => -Middle::missPerUnit($low['price_american'], $high['price_american']),
            'hit_pct' => Middle::hitPerUnit($low['price_american'], $high['price_american']),
            'ev_market' => $best['ev'],
            'ev_model' => $modelHit === null
                ? null
                : Middle::expectedValuePerUnit($low['price_american'], $high['price_american'], $modelHit),
            'stake_split' => Middle::stakeSplitCents($low['price_american'], $high['price_american'], $referenceStake),
            'reference_stake_cents' => $referenceStake,
            'placeable' => $this->allPlaceable([$low, $high]),
            'oldest_quote_seconds' => $this->oldest([$low, $high]),
            'job_run_id' => $jobRunId,
            'detected_at' => $now,
            'created_at' => $now,
        ]);

        return true;
    }

    /**
     * Ranked on expected value, and on width only when the board could not
     * price the gap at all. A wider middle is not automatically a better
     * one: it costs more to hold, and the extra width is already paid for
     * in the two prices.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $best
     */
    private function betterMiddle(array $candidate, array $best): bool
    {
        if ($candidate['ev'] !== null && $best['ev'] !== null) {
            return $candidate['ev'] > $best['ev'];
        }

        if ($candidate['ev'] !== null) {
            return true;
        }

        if ($best['ev'] !== null) {
            return false;
        }

        return $candidate['outs'] > $best['outs'];
    }

    /**
     * What the board says the gap is worth, taken from each leg's own book
     * with that book's margin stripped first.
     *
     * A book quoting only one side of its own market cannot have its margin
     * removed, so there is no fair probability to take from it. That is
     * reported as missing rather than guessed at from the raw price.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $byMarket
     */
    private function marketHitProbability(
        array $byMarket,
        Market $market,
        VigMethod $method,
        Side $lowSide,
        float $lowLine,
        string $lowBookKey,
        Side $highSide,
        float $highLine,
        string $highBookKey,
    ): ?float {
        $lowFair = $this->fairProbability($byMarket, $market, $method, $lowSide, $lowLine, $lowBookKey);
        $highFair = $this->fairProbability($byMarket, $market, $method, $highSide, $highLine, $highBookKey);

        if ($lowFair === null || $highFair === null) {
            return null;
        }

        return Middle::marketHitProbability($lowFair, $highFair);
    }

    /**
     * One book's fair probability for one side at one number, from that
     * book's own two prices on the same market.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $byMarket
     */
    private function fairProbability(
        array $byMarket,
        Market $market,
        VigMethod $method,
        Side $side,
        float $line,
        string $bookKey,
    ): ?float {
        $other = $side->opposite();
        $otherKey = $this->lineKey($this->counterpartLine($market, $line));

        $own = $byMarket[$side->value][$this->lineKey($line)][$bookKey] ?? null;
        $against = $byMarket[$other->value][$otherKey][$bookKey] ?? null;

        if ($own === null || $against === null) {
            return null;
        }

        return VigRemover::fairProbabilities([
            $side->value => $own['price_american'],
            $other->value => $against['price_american'],
        ], $method)[$side->value];
    }

    /**
     * The number the other side of the same market is quoted at. A spread
     * flips sign - home -3 against away +3 - while a total is quoted at
     * one number for both sides.
     */
    private function counterpartLine(Market $market, float $line): float
    {
        return $market === Market::Spread ? -$line : $line;
    }

    private function lineKey(?float $line): string
    {
        return $line === null ? 'null' : (string) $line;
    }

    /**
     * The model estimate that the result lands in the gap, when a model has
     * priced this game. Null otherwise, and never substituted for the
     * board's number: when the two disagree, that disagreement is the
     * finding and averaging it away would hide it.
     *
     * @param  array{low: float, high: float}  $window
     */
    private function modelHitProbability(Event $event, Market $market, array $window): ?float
    {
        $prediction = Prediction::query()
            ->where('event_id', $event->id)
            ->where('market', $market->value)
            ->whereNotNull('sigma')
            ->latest('predicted_at')
            ->latest('id')
            ->first();

        if ($prediction === null) {
            return null;
        }

        $centre = $market === Market::Spread
            ? $prediction->projected_margin
            : $prediction->projected_total;

        $sigma = (float) $prediction->sigma;

        if ($centre === null || $sigma <= 0.0) {
            return null;
        }

        return max(0.0, min(1.0,
            Normal::cdf($window['high'], (float) $centre, $sigma)
            - Normal::cdf($window['low'], (float) $centre, $sigma)
        ));
    }

    /**
     * The biggest payout across the books quoting one side at one number.
     *
     * @param  array<string, array<string, mixed>>  $byBook
     * @return array<string, mixed>|null
     */
    private function bestQuote(array $byBook): ?array
    {
        $best = null;

        foreach ($byBook as $quote) {
            if ($best === null || $quote['price_decimal'] > $best['price_decimal']) {
                $best = $quote;
            }
        }

        return $best;
    }

    private function sportValue(Event $event): string
    {
        return $event->sport instanceof Sport ? $event->sport->value : (string) $event->sport;
    }

    /** @param  array<int|string, array<string, mixed>>  $legs */
    private function allPlaceable(array $legs): bool
    {
        foreach ($legs as $leg) {
            if (! $leg['bettable']) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<int|string, array<string, mixed>>  $legs */
    private function oldest(array $legs): int
    {
        return (int) max(array_map(static fn (array $leg) => $leg['age_seconds'], $legs));
    }
}
