<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ParlayGrade;
use App\Models\User;
use App\Models\UserParlay;
use App\Models\VigilRead;
use App\Models\VlBoardEntry;
use App\Services\Ai\ReadWriter;
use App\Services\Entitlements\Entitlements;
use App\Services\Parlay\ParlayJudge;
use App\Services\Parlay\ParlayLedger;
use App\Services\Parlay\ParlaySlip;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use RuntimeException;

/**
 * The parlay builder.
 *
 * A slip is assembled from board entries and graded by Edge Lab's parlay
 * judge. Vigil computes none of it: legs on the same game are correlated,
 * and multiplying leg probabilities together on this side would produce a
 * number that looked like a parlay probability and was not one.
 *
 * The product's honest position on parlays is that they are usually bad
 * value, because the book's hold compounds with every leg. This page
 * says so with the figure rather than with a warning, which is both more
 * useful and harder to argue with.
 */
class ParlayController extends Controller
{
    public function index(Request $request, ParlaySlip $slip, Entitlements $entitlements): View
    {
        $user = $request->user();

        $latest = ParlayGrade::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        return view('app.parlays', [
            'legs' => $slip->entries(),
            'maxLegs' => ParlaySlip::MAX_LEGS,
            'grade' => $latest,
            'read' => $latest === null ? null : VigilRead::query()
                ->where('subject_type', 'parlay')
                ->where('subject_id', $latest->id)
                ->currentVersion()
                ->first(),
            'gradesToday' => $this->gradesToday($user),
            'gradeLimit' => $entitlements->limit($user, 'parlays_per_day'),
            // Candidates to add: only picks the product is allowed to
            // sell. A slip built from unproven markets would be graded
            // against probabilities nobody may act on.
            'candidates' => VlBoardEntry::query()->current()->sellablePicks()
                ->orderByDesc('edge_at_price')
                ->limit(20)
                ->get()
                ->reject(fn (VlBoardEntry $e) => in_array($e->id, $slip->ids(), true))
                ->values(),
        ]);
    }

    public function add(Request $request, ParlaySlip $slip): RedirectResponse
    {
        $data = $request->validate([
            'board_entry_id' => ['required', 'integer', 'exists:vl_board_entries,id'],
        ]);

        $entry = VlBoardEntry::findOrFail($data['board_entry_id']);

        /*
         * The gate, again. A slip is built from board entries and a
         * board entry id is a number anybody can type - a leg from an
         * unproven market would be graded against a probability the
         * product is not allowed to sell.
         */
        if (! $entry->isSellablePick()) {
            return back()->withErrors(['slip' => 'That selection is not a pick from a proven market.']);
        }

        if (($refusal = $slip->add($entry)) !== null) {
            return back()->withErrors(['slip' => $refusal]);
        }

        return back();
    }

    public function remove(Request $request, ParlaySlip $slip): RedirectResponse
    {
        $slip->remove((int) $request->integer('board_entry_id'));

        return back();
    }

    public function clear(ParlaySlip $slip): RedirectResponse
    {
        $slip->clear();

        return back();
    }

    /**
     * Grade the slip.
     *
     * Rate limited per tier, per §4: three a day on the free plan,
     * unlimited on a paid one. Counted against stored grades, which are
     * append-only, rather than against a session counter - a limit
     * enforced against something clearable is not a limit.
     */
    public function grade(
        Request $request,
        ParlaySlip $slip,
        ParlayJudge $judge,
        ReadWriter $reads,
        Entitlements $entitlements,
    ): RedirectResponse {
        $user = $request->user();

        $limit = $entitlements->limit($user, 'parlays_per_day');

        if ($limit !== null && $this->gradesToday($user) >= $limit) {
            return back()->withErrors([
                'slip' => 'Your plan grades '.$limit.' '.str('parlay')->plural($limit)
                    .' a day. The slip is still here tomorrow.',
            ]);
        }

        $legs = $slip->legsForJudge();

        if (count($legs) < 2) {
            return back()->withErrors(['slip' => 'A parlay needs at least two legs.']);
        }

        try {
            $grade = $judge->judge($user, $legs);
        } catch (RuntimeException $e) {
            return back()->withErrors(['slip' => $e->getMessage()]);
        }

        /*
         * The Read on the result, per §6. Attributed to this customer,
         * because unlike a board Read this one was generated for one
         * person and counts against their generation allowance.
         */
        $reads->forParlay($grade, for: $user);

        return back();
    }

    /**
     * Save the slip as a tracked parlay.
     *
     * A draft by default. The slip is a scratchpad and saving it is not a
     * claim that money was placed, so "record as placed" is a separate
     * tick the user has to make - and only that tick moves the bankroll.
     */
    public function save(Request $request, ParlaySlip $slip, ParlayLedger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'stake' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'book' => ['nullable', 'string', 'max:64'],
            'accepted_price' => ['nullable', 'integer', 'between:-100000,100000'],
            'ticket_reference' => ['nullable', 'string', 'max:64'],
            'placed' => ['nullable', 'boolean'],
            'is_paper' => ['nullable', 'boolean'],
        ]);

        $entries = $slip->entries();

        if ($entries->count() < ParlayLedger::MINIMUM_LEGS) {
            return back()->withErrors(['slip' => 'A parlay needs at least two legs.']);
        }

        /*
         * A leg with no price cannot be saved: the combined price would
         * be wrong and the payout computed from it would be fiction.
         */
        $unpriced = $entries->first(fn (VlBoardEntry $entry): bool => $entry->best_price_american === null);

        if ($unpriced !== null) {
            return back()->withErrors([
                'slip' => 'No price on hand for '.$unpriced->selection.'. Remove it or wait for the next odds pull.',
            ]);
        }

        try {
            $parlay = $ledger->save(
                $request->user(),
                $entries->map(fn (VlBoardEntry $entry): array => $this->legFrom($entry))->all(),
                [
                    'stake_cents' => (int) round((float) $data['stake'] * 100),
                    'book' => $data['book'] ?? null,
                    'accepted_price_american' => $data['accepted_price'] ?? null,
                    'ticket_reference' => $data['ticket_reference'] ?? null,
                    'placed' => (bool) ($data['placed'] ?? false),
                    'is_paper' => (bool) ($data['is_paper'] ?? false),
                ],
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['slip' => $e->getMessage()]);
        }

        /*
         * The slip is cleared, because leaving it full after saving makes
         * the next save a duplicate of this one - which is how a tracker
         * ends up reporting bets nobody placed.
         */
        $slip->clear();

        return to_route('my-parlays')->with('status', $parlay->status === 'draft'
            ? 'Saved as a draft. It is not counted as a wager until you record it as placed.'
            : 'Recorded. Legs will settle as the games finish.');
    }

    /**
     * One leg, with what the engine thought about it at this moment.
     *
     * Frozen rather than looked up later. The comparison the product
     * promises - did VIGIL agree with this pick - has to be answered as
     * of when the user chose it, and the models retrain weekly.
     *
     * @return array<string, mixed>
     */
    private function legFrom(VlBoardEntry $entry): array
    {
        return [
            'edge_lab_event_id' => $entry->edge_lab_event_id,
            'board_entry_id' => $entry->id,
            'sport' => (string) $entry->sport,
            'market' => (string) $entry->market,
            'side' => $entry->side,
            'line' => $entry->line,
            'selection' => (string) $entry->selection,
            'price_american' => (int) $entry->best_price_american,
            'model_probability' => $entry->model_prob,
            'model_version' => $entry->payload['model_version'] ?? null,
            'model_edge' => $entry->edge_at_price ?? $entry->edge,
            /*
             * The engine's own verdict on this selection, in its own
             * terms. "Passed" is not "disagreed": the engine having a
             * probability and declining the price is a different thing
             * from it thinking the other side wins, and flattening the
             * two would misreport what it said.
             */
            'model_agreement' => match ($entry->recommendation) {
                'bet' => 'agreed',
                'pass' => 'passed',
                default => 'no_prediction',
            },
        ];
    }

    /**
     * The customer's own saved parlays.
     *
     * Separate from the engine's picks by design: §0.3 keeps VIGIL's
     * opinions and the customer's decisions apart so neither can be
     * mistaken for the other, and a tracker that mixed them would make
     * the engine's record unreadable.
     *
     * Drafts are listed above live bets rather than hidden. A draft is
     * something the user started and did not finish, and the one place
     * they will look for it is here.
     */
    public function mine(Request $request): View
    {
        $parlays = UserParlay::query()
            ->where('user_id', $request->user()->id)
            ->with('legs')
            ->orderByRaw("case when status = 'draft' then 0 else 1 end")
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('app.my-parlays', [
            'parlays' => $parlays,
            'summary' => $this->summaryOf($parlays),
        ]);
    }

    /**
     * The figures for the strip above the list.
     *
     * Real money only, and settled only. A paper parlay belongs in the
     * record of what the customer would have done and nowhere near a
     * return figure, and a pending parlay has no result to report - so
     * counting either would describe something that has not happened.
     *
     * @param  Collection<int, UserParlay>  $parlays
     * @return array<string, mixed>
     */
    private function summaryOf($parlays): array
    {
        $settled = $parlays->filter(
            fn (UserParlay $p): bool => ! $p->is_paper && in_array($p->status, ['won', 'lost', 'push'], true)
        );

        $staked = (int) $settled->sum('stake_cents');
        $returned = (int) $settled->sum('return_cents');
        $decided = $settled->whereIn('status', ['won', 'lost']);

        return [
            'drafts' => $parlays->where('status', 'draft')->count(),
            'pending' => $parlays->where('status', 'pending')->count(),
            'settled' => $settled->count(),
            'staked_cents' => $staked,
            'profit_cents' => $settled->isEmpty() ? null : $returned - $staked,
            /*
             * Both rates carry their sample or are not shown, the rule
             * the public record page already follows. A win rate over
             * three parlays is a number about three parlays.
             */
            'decided' => $decided->count(),
            'win_rate' => $decided->isEmpty() ? null : $decided->where('status', 'won')->count() / $decided->count(),
            'roi' => $staked === 0 ? null : ($returned - $staked) / $staked,
        ];
    }

    private function gradesToday(User $user): int
    {
        return ParlayGrade::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', today())
            ->count();
    }
}
