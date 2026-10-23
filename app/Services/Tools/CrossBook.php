<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Models\VlEvent;
use App\Models\VlOddsDisplay;
use Illuminate\Support\Collection;

/**
 * Arbitrage and middles, found in the quotes already stored.
 *
 * Computed here rather than fetched, because there is nothing to fetch:
 * an arb is arithmetic on two prices this product already holds, and
 * Edge Lab exposes no endpoint for it. Nothing is written back and no new
 * odds are pulled, so §2's read-only boundary and §9's no-bulk-odds rule
 * both hold.
 *
 * It only ever sees games somebody has already looked at, because that is
 * all the display mirror contains. That is a deliberate limitation rather
 * than a gap: a cross-book scanner over every game in every league is
 * exactly the bulk-odds product the licence forbids.
 */
class CrossBook
{
    /**
     * Two prices on opposite sides whose implied probabilities sum to
     * less than one: a guaranteed profit if both are still there.
     *
     * The caveats are the point, so they are returned with the figure
     * rather than written on the page: these are stored quotes, not live
     * ones, and an arb that existed four minutes ago usually does not.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function arbitrage(float $minimumProfit = 0.001): Collection
    {
        return $this->pairs()
            ->map(function (array $pair) {
                $sum = $this->implied($pair['a']->price_american)
                    + $this->implied($pair['b']->price_american);

                return array_merge($pair, [
                    // Under 1 means the two prices between them pay out
                    // more than a certain outcome is worth.
                    'implied_sum' => round($sum, 5),
                    'profit' => round(1 - $sum, 5),
                ]);
            })
            ->filter(fn (array $pair) => $pair['profit'] >= $minimumProfit)
            ->sortByDesc('profit')
            ->values();
    }

    /**
     * Two sides of a handicap or total whose lines leave a gap both can
     * win in.
     *
     * A middle is not free money - it needs the result to land inside
     * the gap - so the width is returned rather than a verdict, and the
     * page says what it costs when it misses.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function middles(): Collection
    {
        return $this->pairs(['spreads', 'totals'])
            ->filter(fn (array $pair) => $pair['a']->line !== null && $pair['b']->line !== null)
            ->map(function (array $pair) {
                /*
                 * For a total, over X and under Y middle when Y > X. For
                 * a spread, home -X and away +Y middle when the sum of
                 * the two handicaps leaves room.
                 */
                $width = $pair['market'] === 'totals'
                    ? (float) $pair['b']->line - (float) $pair['a']->line
                    : (float) $pair['a']->line + (float) $pair['b']->line;

                return array_merge($pair, [
                    'width' => round(abs($width), 2),
                    'both_win' => $width > 0,
                    'cost' => round(
                        $this->implied($pair['a']->price_american)
                        + $this->implied($pair['b']->price_american) - 1,
                        5,
                    ),
                ]);
            })
            ->filter(fn (array $pair) => $pair['both_win'] && $pair['width'] > 0)
            ->sortByDesc('width')
            ->values();
    }

    /**
     * Every pair of opposing latest quotes, across books, per market.
     *
     * "Latest" per book and side, because two stale quotes from the same
     * book at different times are not two prices a customer could take.
     *
     * @param  array<int, string>  $markets
     * @return Collection<int, array<string, mixed>>
     */
    private function pairs(array $markets = ['h2h', 'spreads', 'totals']): Collection
    {
        $quotes = VlOddsDisplay::query()
            ->whereIn('market', $markets)
            ->whereNotNull('price_american')
            ->whereNull('player_name')
            ->orderByDesc('quoted_at')
            ->limit(2_000)
            ->get()
            // One quote per event, market, side and book: the newest.
            ->unique(fn (VlOddsDisplay $q) => $q->edge_lab_event_id.'|'.$q->market.'|'.$q->side.'|'.$q->book);

        $names = $this->eventNames($quotes->pluck('edge_lab_event_id')->unique()->all());

        $pairs = collect();

        foreach ($quotes->groupBy(fn (VlOddsDisplay $q) => $q->edge_lab_event_id.'|'.$q->market) as $key => $group) {
            [$eventId, $market] = explode('|', (string) $key, 2);

            $sides = $group->groupBy('side');

            if ($sides->count() < 2) {
                continue;
            }

            [$first, $second] = $sides->keys()->take(2)->all();

            foreach ($sides[$first] as $a) {
                foreach ($sides[$second] as $b) {
                    // Same book on both sides is not a cross-book
                    // opportunity; a book does not price itself into an
                    // arb, and if it looks like one the data is stale.
                    if ($a->book === $b->book) {
                        continue;
                    }

                    $pairs->push([
                        'event_id' => (int) $eventId,
                        'matchup' => $names[(int) $eventId] ?? null,
                        'market' => $market,
                        'a' => $a,
                        'b' => $b,
                        // The older of the two quotes, because that is
                        // how stale the opportunity actually is.
                        'as_of' => $a->quoted_at->lt($b->quoted_at) ? $a->quoted_at : $b->quoted_at,
                    ]);
                }
            }
        }

        return $pairs;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function eventNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return VlEvent::query()->current()
            ->whereIn('edge_lab_id', $ids)
            ->get()
            ->mapWithKeys(fn (VlEvent $e) => [$e->edge_lab_id => $e->matchup()])
            ->all();
    }

    /** Implied probability of an American price, vig included. */
    private function implied(?int $price): float
    {
        if ($price === null || $price === 0) {
            return 1.0;
        }

        return $price > 0
            ? 100 / ($price + 100)
            : abs($price) / (abs($price) + 100);
    }
}
