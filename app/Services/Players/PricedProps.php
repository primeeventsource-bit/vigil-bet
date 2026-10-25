<?php

declare(strict_types=1);

namespace App\Services\Players;

use App\Enums\VigMethod;
use App\Modeling\Odds\MarketConsensus;
use Illuminate\Support\Facades\DB;

/**
 * The prop board as one row per player, market and line.
 *
 * Shared by the report and the writer rather than written twice, because
 * the two must not be able to disagree about what was on the board: a
 * report that measures one population and a record that stores another
 * produces a calibration figure for a board that never existed.
 *
 * The newest quote per book and side wins. Two stale quotes from one
 * book are not two prices to shop between, and a price nobody is still
 * offering is not a price.
 */
class PricedProps
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $memo = [];

    /**
     * Every projectable prop on a game still to come.
     *
     * Memoised for the length of the request, because the report and
     * the writer both ask for it and the second call was what exhausted
     * the memory limit in production: props are pulled every five
     * minutes, so one evening's board is a couple of hundred thousand
     * quote rows and holding two copies of it is not affordable.
     *
     * @return array<int, array<string, mixed>>
     */
    public function upcoming(?string $sport = null, ?string $market = null): array
    {
        $memoKey = ($sport ?? '*').'|'.($market ?? '*');

        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $markets = $market !== null ? [$market] : RatingSchema::projectableProps();

        if ($markets === []) {
            return $this->memo[$memoKey] = [];
        }

        $quotes = DB::table('odds_snapshots as o')
            ->join('events as e', 'e.id', '=', 'o.event_id')
            ->join('books as b', 'b.id', '=', 'o.book_id')
            ->whereIn('o.market_key', $markets)
            ->whereNotNull('o.player_name')
            ->whereNotNull('o.price_american')
            ->where('e.starts_at', '>', now())
            /*
             * Recent pulls only, which is cheaper and also more
             * correct. Props are pulled every five minutes, so without
             * this the query returns every quote ever taken on tonight's
             * games - a couple of hundred thousand rows, almost all of
             * them superseded - and a price nobody is still offering is
             * not a price. Six hours is many cadences' worth of margin
             * for a feed that has stopped.
             */
            ->where('o.pulled_at', '>', now()->subHours(
                (int) config('edge.props.quote_freshness_hours', 6)
            ))
            ->when($sport, fn ($q) => $q->where('e.sport', $sport))
            ->orderBy('o.pulled_at')
            ->get([
                'e.sport', 'e.starts_at', 'o.event_id', 'o.market_key',
                'o.player_name', 'o.line', 'o.side', 'o.price_american',
                'b.key as book', 'o.pulled_at',
            ]);

        $byLine = [];

        foreach ($quotes as $quote) {
            $line = $this->lineFor((string) $quote->market_key, $quote->line);

            $key = implode('|', [
                $quote->event_id, $quote->market_key, $quote->player_name, (string) $line,
            ]);

            $byLine[$key]['meta'] ??= [
                'sport' => (string) $quote->sport,
                'event_id' => (int) $quote->event_id,
                'market_key' => (string) $quote->market_key,
                'player_name' => (string) $quote->player_name,
                'line' => $line,
                'kickoff_at' => (string) $quote->starts_at,
            ];

            // Last write per book and side wins, and the query is
            // ordered by when it was pulled.
            $byLine[$key]['prices'][$quote->book][$quote->side] = (int) $quote->price_american;
        }

        $out = [];

        foreach ($byLine as $entry) {
            $out[] = $entry['meta'] + ['prices' => $entry['prices']];
        }

        return $this->memo[$memoKey] = $out;
    }

    /**
     * The market's fair probability for one side, and how many books
     * had a say.
     *
     * Vig removed per book and only then combined, which is the order
     * that matters: averaging the raw implied probabilities first blends
     * the overrounds together and produces a market no book offered -
     * usually one that looks beatable before a model has been consulted.
     *
     * A book quoting one side does not get a vote, because a one-sided
     * price cannot have its vig removed. That is not a rare case here:
     * every anytime touchdown quote on the board is yes-only, so the
     * market's fair price for the largest prop market there is cannot be
     * computed at all, and null says so rather than guessing a hold.
     *
     * @param  array<string, mixed>  $row
     * @return array{fair: float|null, books: int}
     */
    public function fair(array $row, string $side = 'over'): array
    {
        $model = RatingSchema::modelForProp((string) $row['market_key']);

        if ($model === null) {
            return ['fair' => null, 'books' => 0];
        }

        $sides = $model['sides'];

        // A yes/no market is asked about in over/under terms, because
        // that is what a projection produces.
        $wanted = match (true) {
            in_array($side, $sides, true) => $side,
            $side === 'over' => $sides[0],
            default => $sides[1],
        };

        $consensus = MarketConsensus::fairProbability(
            $row['prices'],
            $wanted,
            $sides,
            VigMethod::tryFrom((string) config('edge.value.vig_method')) ?? VigMethod::Multiplicative,
        );

        return [
            'fair' => $consensus?->fairProbability,
            'books' => $consensus?->bookCount ?? 0,
        ];
    }

    /**
     * The best price on offer for one side, or null.
     *
     * Highest American price is best for the bettor, and that holds
     * across the sign boundary: +105 beats -105 beats -120.
     *
     * @param  array<string, mixed>  $row
     */
    public function bestPrice(array $row, string $side): ?int
    {
        $model = RatingSchema::modelForProp((string) $row['market_key']);
        $sides = $model['sides'] ?? ['over', 'under'];

        $wanted = match (true) {
            in_array($side, $sides, true) => $side,
            $side === 'over' => $sides[0],
            default => $sides[1],
        };

        $best = null;

        foreach ($row['prices'] as $prices) {
            if (! isset($prices[$wanted])) {
                continue;
            }

            $best = $best === null ? (int) $prices[$wanted] : max($best, (int) $prices[$wanted]);
        }

        return $best;
    }

    /**
     * The line, with a yes/no market given the one it implies.
     *
     * Anytime touchdown has no line in the feed because it is not a
     * line - it is "did he score". Written as 0.5 so the stored row
     * means the same thing as every other: over 0.5 touchdowns is
     * exactly one or more, and it cannot be pushed, which is also true
     * of the bet.
     */
    private function lineFor(string $marketKey, mixed $line): float
    {
        $model = RatingSchema::modelForProp($marketKey);

        if (($model['family'] ?? null) === 'threshold') {
            return (float) ($model['threshold'] ?? 1.0) - 0.5;
        }

        return (float) $line;
    }
}
