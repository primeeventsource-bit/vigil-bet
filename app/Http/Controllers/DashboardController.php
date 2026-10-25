<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Opinion;
use App\Models\User;
use App\Models\VigilRead;
use App\Models\VlBoardEntry;
use App\Models\VlEvent;
use App\Models\VlModel;
use App\Models\VlOddsDisplay;
use App\Services\Ai\ReadQuota;
use App\Services\Ai\ResearchBrief;
use App\Services\EdgeLab\SyncHealth;
use App\Services\Entitlements\Entitlements;
use App\Services\Record\OpinionRecord;
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
    /** How many picks a customer sees on the dashboard without the daily card. */
    private const FREE_PICKS = 3;

    public function __invoke(
        Request $request,
        SyncHealth $health,
        Entitlements $entitlements,
        ReadQuota $quota,
        ResearchBrief $research,
        OpinionRecord $opinionRecord,
    ): View {
        $sport = $request->query('sport');
        $user = $request->user();

        /*
         * Free sees the board and the top few picks; the daily card is
         * what buys every pick and the reasons behind the passes. Both
         * decisions are made here rather than in the template, because a
         * template that filters is a template somebody reuses without
         * the filter.
         */
        $fullCard = $entitlements->can($user, 'card.daily');

        $query = VlBoardEntry::query()->current()->sellablePicks()
            ->when($sport, fn ($q) => $q->where('sport', $sport));

        $available = (clone $query)->count();

        $picks = $query->orderByDesc('edge_at_price')
            ->limit($fullCard ? 10 : self::FREE_PICKS)
            ->get();

        /*
         * Why the engine declined a game is the daily card's promise, so
         * it is not shown to somebody who has not got it.
         */
        $nearMisses = $picks->isEmpty() && $fullCard
            ? VlBoardEntry::query()->current()->nearMisses()->limit(5)->get()
            : collect();

        /*
         * The day's published opinions, which is what this page is for
         * while no market is proven. Live only - a game that has started
         * is no longer a pick anybody can act on, and leaving it up
         * would pad the card with selections that are already settled
         * in everything but name.
         */
        $opinions = Opinion::query()->live()->with('event:id,starts_at')->limit(12)->get();

        return view('app.dashboard', [
            'picks' => $picks,
            'opinions' => $opinions,
            /*
             * The accuracy of everything published so far, shown beside
             * today's card. A picks page without its record is a claim
             * with nowhere to check it, and the figures come back null
             * rather than zero until something has been graded.
             */
            'opinionRecord' => $opinionRecord->summary(),
            // Only sports the engine has actually judged. A tab with
            // nothing behind it is a dead end dressed as a feature.
            'availableSports' => VlBoardEntry::query()->current()->sellablePicks()
                ->distinct()->orderBy('sport')->pluck('sport'),
            // Withheld, not missing. A customer told "no picks today"
            // when there were seven would be being lied to.
            'picksWithheld' => max(0, $available - $picks->count()),
            'nearMisses' => $nearMisses,
            /*
             * The research behind each near-miss, and the Read that
             * explains it. Without these the card showed a selection, a
             * price and an engine pass reason - machinery, not an
             * educated guess - while form, availability and rest sat in
             * the database unread.
             */
            'nearMissReads' => $this->readsFor($user, $nearMisses, $quota),
            'nearMissResearch' => $this->researchFor($nearMisses, $research),
            'activePicks' => $picks->count(),
            // The mockup calls this confidence. It is the model
            // probability Edge Lab stored, under the design's label -
            // not a second number invented to fill the tile.
            'avgConfidence' => $this->averageModelProbability($picks),
            'sports' => $this->confidenceBySport(),
            'movement' => $this->lineMovement(),
            'bankroll' => $this->bankroll(),
            'delayed' => $health->customerNotice(['board', 'models']),
            // Reads, in pick order, until the day's allowance runs out. See
            // readsFor(): the top pick gets the explanation, which is
            // the one a customer on one-a-day would want.
            'reads' => $this->readsFor($user, $picks, $quota),
            'readsRemaining' => $quota->viewsRemaining($user),
        ]);
    }

    /**
     * The Read for each pick, as far as the allowance goes.
     *
     * Claimed in pick order rather than all at once, so a customer on
     * one-a-day spends it on the strongest pick rather than on whichever
     * row the database happened to return first.
     *
     * A Read that is not claimed is absent from the map, and the card
     * says why rather than silently showing nothing - a customer who
     * cannot tell a used-up allowance from a missing explanation will
     * assume the product is broken.
     *
     * @param  Collection<int, VlBoardEntry>  $picks
     * @return Collection<int, VigilRead> keyed by board entry id
     */
    /**
     * The research behind each entry, keyed by entry id.
     *
     * One brief per game rather than per entry, because two selections
     * on the same game share it and building it twice would double the
     * queries for an identical answer.
     *
     * @param  Collection<int, VlBoardEntry>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function researchFor(Collection $entries, ResearchBrief $research): Collection
    {
        if ($entries->isEmpty()) {
            return collect();
        }

        $briefs = [];
        $byEntry = collect();

        foreach ($entries as $entry) {
            $eventId = $entry->edge_lab_event_id;

            if ($eventId === null) {
                continue;
            }

            $briefs[$eventId] ??= $research->forEvent((int) $eventId);

            if ($briefs[$eventId] !== []) {
                $byEntry->put($entry->id, $briefs[$eventId]);
            }
        }

        return $byEntry;
    }

    private function readsFor(?User $user, Collection $picks, ReadQuota $quota): Collection
    {
        if ($picks->isEmpty() || $user === null) {
            return collect();
        }

        $reads = VigilRead::query()
            ->where('subject_type', 'board_entry')
            ->whereIn('subject_id', $picks->pluck('id'))
            ->currentVersion()
            ->get()
            ->keyBy('subject_id');

        $claimed = collect();

        foreach ($picks as $pick) {
            $read = $reads->get($pick->id);

            if ($read !== null && $quota->claim($user, $read)) {
                $claimed->put($pick->id, $read);
            }
        }

        return $claimed;
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
