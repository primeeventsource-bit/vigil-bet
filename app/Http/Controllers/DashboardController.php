<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VlBoardEntry;
use App\Models\VlEvent;
use App\Models\VlModel;
use App\Models\VlOddsDisplay;
use App\Services\EdgeLab\SyncHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The dashboard.
 *
 * Every figure on this page is read from a row Edge Lab wrote, or from
 * this customer's own settled record. Nothing is estimated to fill a
 * panel: where there is no data the panel says so, because a dashboard
 * that invents a plausible number is worse than one with a gap in it.
 *
 * That is why so much here can return null. The design shows a full
 * screen of figures; until a market is proven and bets are settled, the
 * honest version of that screen is mostly empty, and the empty state is
 * the product working.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, SyncHealth $health): View
    {
        $sport = $request->query('sport');

        $picks = VlBoardEntry::query()->current()->sellablePicks()
            ->when($sport, fn ($q) => $q->where('sport', $sport))
            ->orderByDesc('edge_at_price')
            ->limit(10)
            ->get();

        return view('app.dashboard', [
            'picks' => $picks,
            // Only sports the engine has actually judged. A tab with
            // nothing behind it is a dead end dressed as a feature.
            'availableSports' => VlBoardEntry::query()->current()->sellablePicks()
                ->distinct()->orderBy('sport')->pluck('sport'),
            'nearMisses' => $picks->isEmpty()
                ? VlBoardEntry::query()->current()->nearMisses()->limit(5)->get()
                : collect(),
            'activePicks' => $picks->count(),
            // The mockup calls this confidence. It is the model
            // probability Edge Lab stored, under the design's label -
            // not a second number invented to fill the tile.
            'avgConfidence' => $this->averageModelProbability($picks),
            'sports' => $this->confidenceBySport(),
            'movement' => $this->lineMovement(),
            'bankroll' => $this->bankroll(),
            'delayed' => $health->customerNotice(['board', 'models']),
        ]);
    }

    private function averageModelProbability(Collection $picks): ?float
    {
        $probs = $picks->pluck('model_prob')->filter(fn ($p) => $p !== null);

        return $probs->isEmpty() ? null : (float) $probs->avg();
    }

    /**
     * Average model probability per sport, across what the engine has
     * actually judged. Null for a sport nothing has been scanned for,
     * which renders as an em dash rather than a zero - zero would read
     * as "the model is certain it loses".
     *
     * @return Collection<int, array{sport:string, value:?float, verdict:?string}>
     */
    private function confidenceBySport(): Collection
    {
        $verdicts = VlModel::query()->current()->get()->groupBy('sport');

        return VlBoardEntry::query()
            ->current()
            ->whereNotNull('model_prob')
            ->get()
            ->groupBy('sport')
            ->map(fn (Collection $rows, string $sport) => [
                'sport' => strtoupper($sport),
                'value' => (float) $rows->avg('model_prob'),
                // Carried so the UI can say which of these may produce a
                // pick at all, rather than implying all of them may.
                'verdict' => $verdicts->get($sport)?->first()?->verdict,
            ])
            ->values();
    }

    /**
     * Prices that moved, newest first.
     *
     * Read from the display mirror, which only ever holds games somebody
     * has looked at - there is no bulk odds feed on either side of this
     * product and this panel is not allowed to become one.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function lineMovement(): Collection
    {
        // Names for the ids, so the panel reads as games rather than as
        // a column of market codes.
        $events = VlEvent::query()->current()->get()
            ->mapWithKeys(fn (VlEvent $e) => [
                $e->edge_lab_id => trim(($e->away_abbreviation ?? '').' @ '.($e->home_abbreviation ?? '')),
            ]);

        return VlOddsDisplay::query()
            ->coreMarkets()
            ->orderByDesc('observed_at')
            ->limit(60)
            ->get()
            ->groupBy(fn (VlOddsDisplay $q) => $q->edge_lab_event_id.'|'.$q->market.'|'.$q->side.'|'.$q->book)
            ->filter(fn (Collection $quotes) => $quotes->count() >= 2)
            ->map(function (Collection $quotes) use ($events) {
                $latest = $quotes->first();
                $previous = $quotes->get(1);

                return [
                    'event_id' => $latest->edge_lab_event_id,
                    'matchup' => $events->get($latest->edge_lab_event_id),
                    'market' => $latest->market,
                    'line' => $latest->line,
                    'from' => $previous->price_american,
                    'to' => $latest->price_american,
                    // Direction carries an arrow and a word as well as a
                    // colour; roughly one man in twelve cannot use the
                    // colour on its own.
                    'direction' => $latest->price_american > $previous->price_american ? 'up' : 'down',
                ];
            })
            ->values()
            ->take(5);
    }

    /**
     * The customer's bankroll.
     *
     * Not built yet - it arrives with My Bets at step 8 - so this returns
     * nulls and the panel says it is not set up. The alternative, a
     * seeded balance and a rising line, would be a fabricated track
     * record on the first screen of the product.
     *
     * @return array<string, mixed>
     */
    private function bankroll(): array
    {
        return [
            'balance_cents' => null,
            'change_pct' => null,
            'series' => [],
            'labels' => [],
            'win_rate' => null,
            'roi' => null,
            'units' => null,
            'settled' => 0,
        ];
    }
}
