<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\Odds\GameMarkets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Every book's price for one game, side by side.
 *
 * Distinct from Today's Games, which shows the best of N and does not
 * say who is offering it. That is the right summary for building a
 * parlay and the wrong one for deciding where to place a bet, and the
 * product had no page for the second question.
 *
 * Gated on `lineshopping`, which is the feature key that has described
 * exactly this since the plans were written and had no page behind it.
 *
 * One limit, stated on the page rather than worked around: the product
 * only holds quotes for games somebody has already opened. That is the
 * data licence doing its job, not a gap to be filled by scraping.
 */
class OddsComparisonController extends Controller
{
    private const FRESH_HOURS = 24;

    public function index(Request $request, GameMarkets $markets): View
    {
        $sport = (string) $request->query('sport', '');

        $games = $markets->upcoming($sport !== '' ? $sport : null, 72);

        return view('app.odds', [
            'games' => $games,
            'sports' => $markets->sportsWithGames(72),
            'sport' => $sport,
            'pricedAt' => $markets->lastPricedAt(),
        ]);
    }

    /**
     * One game, every book, every market.
     *
     * Built from odds_snapshots directly rather than through
     * GameMarkets, because that service deliberately reduces to the
     * best price per selection - which is the opposite of what this
     * page is for.
     */
    public function show(Request $request, Event $event): View
    {
        $rows = DB::table('odds_snapshots as o')
            ->join('books as b', 'b.id', '=', 'o.book_id')
            ->where('o.event_id', $event->getKey())
            ->whereIn('o.market', GameMarkets::MARKETS)
            ->where('o.pulled_at', '>', now()->subHours(self::FRESH_HOURS))
            ->orderBy('o.pulled_at')
            ->get([
                'o.market', 'o.side', 'o.line', 'o.price_american', 'o.pulled_at',
                'b.key as book', 'b.title as book_title',
            ]);

        /*
         * Latest quote per book per selection. A snapshot table holds
         * every pull, and this morning's price is not an offer - but
         * showing one book's stale number beside another's current one
         * is worse than showing neither, because the gap between them
         * reads as an opportunity.
         */
        $latest = [];

        foreach ($rows as $row) {
            $latest[$row->market.'|'.$row->side.'|'.($row->line ?? '').'|'.$row->book] = $row;
        }

        $markets = [];

        foreach ($latest as $row) {
            $markets[$row->market][$row->side.'|'.($row->line ?? '')][] = $row;
        }

        // Best price per selection, so the grid can mark it without the
        // reader comparing twelve numbers by eye.
        $best = [];

        foreach ($markets as $market => $selections) {
            foreach ($selections as $key => $quotes) {
                $best[$market.'|'.$key] = collect($quotes)->max('price_american');
            }
        }

        return view('app.odds-detail', [
            'event' => $event->load(['homeTeam:id,display_name,abbreviation', 'awayTeam:id,display_name,abbreviation']),
            'markets' => $markets,
            'best' => $best,
            'books' => collect($latest)->pluck('book_title', 'book')->unique()->sort()->all(),
            'freshHours' => self::FRESH_HOURS,
        ]);
    }
}
