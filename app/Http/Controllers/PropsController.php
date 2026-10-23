<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VlEvent;
use App\Models\VlOddsDisplay;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The player props board, with line shopping.
 *
 * Props are collected and displayed; they are not modelled. Edge Lab
 * ingests them into a column the model pipeline cannot read - the schema
 * enforces that, not a convention - so there is no probability, no edge
 * and no verdict on this page, and there must never appear to be one.
 *
 * What it is good for is line shopping: the same player and number priced
 * differently across books is a real, checkable advantage that needs no
 * model at all. That is the whole feature, said plainly.
 */
class PropsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $sport = $request->query('sport');

        $quotes = VlOddsDisplay::query()
            ->props()
            ->whereNotNull('price_american')
            ->orderByDesc('quoted_at')
            ->limit(1_500)
            ->get()
            // Newest per player, market, line, side and book. Two stale
            // quotes from one book are not two prices to shop between.
            ->unique(fn (VlOddsDisplay $q) => implode('|', [
                $q->edge_lab_event_id, $q->player_name, $q->market_key, $q->line, $q->side, $q->book,
            ]));

        $events = $this->events($quotes->pluck('edge_lab_event_id')->unique()->all(), $sport);

        return view('app.props', [
            'groups' => $this->shop($quotes, $events),
            'sports' => $events->pluck('sport')->unique()->sort()->values(),
            'sport' => $sport,
        ]);
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, VlEvent>
     */
    private function events(array $ids, ?string $sport): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return VlEvent::query()->current()
            ->whereIn('edge_lab_id', $ids)
            ->when($sport, fn ($q) => $q->where('sport', $sport))
            ->get()
            ->keyBy('edge_lab_id');
    }

    /**
     * One row per player, market, line and side, with every book's price
     * and the best of them marked.
     *
     * The best price is the whole point: on a prop with no model behind
     * it, the only edge available is not taking a worse number than
     * somebody else is offering.
     *
     * @param  Collection<int, VlOddsDisplay>  $quotes
     * @param  Collection<int, VlEvent>  $events
     * @return Collection<int, array<string, mixed>>
     */
    private function shop(Collection $quotes, Collection $events): Collection
    {
        return $quotes
            ->filter(fn (VlOddsDisplay $q) => $events->has($q->edge_lab_event_id))
            ->groupBy(fn (VlOddsDisplay $q) => implode('|', [
                $q->edge_lab_event_id, $q->player_name, $q->market_key, $q->line, $q->side,
            ]))
            ->map(function (Collection $books) use ($events) {
                $first = $books->first();

                // Highest American price is the best for the bettor,
                // which is true across the sign boundary: +105 beats
                // -105 beats -120.
                $best = $books->sortByDesc('price_american')->first();

                return [
                    'matchup' => $events->get($first->edge_lab_event_id)?->matchup(),
                    'sport' => $events->get($first->edge_lab_event_id)?->sport,
                    'player' => $first->player_name,
                    'market' => $first->market_key,
                    'side' => $first->side,
                    'line' => $first->line,
                    'best_book' => $best->book_title ?? $best->book,
                    'best_price' => $best->price_american,
                    'books' => $books->sortByDesc('price_american')->values(),
                    // How far the worst price is from the best. The
                    // number that tells a customer whether shopping was
                    // worth the trouble.
                    'spread' => $books->count() < 2
                        ? null
                        : (int) $best->price_american - (int) $books->sortBy('price_american')->first()->price_american,
                    'as_of' => $books->max('quoted_at'),
                ];
            })
            ->sortByDesc('spread')
            ->values();
    }
}
