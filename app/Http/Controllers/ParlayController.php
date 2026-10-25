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
use App\Services\Odds\GameMarkets;
use App\Services\Parlay\ParlayJudge;
use App\Services\Parlay\ParlayLedger;
use App\Services\Parlay\ParlaySlip;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
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
            'legs' => $slip->selections(),
            'combined' => $slip->combinedPriceAmerican(),
            'maxLegs' => ParlaySlip::MAX_LEGS,
            'grade' => $latest,
            'read' => $latest === null ? null : VigilRead::query()
                ->where('subject_type', 'parlay')
                ->where('subject_id', $latest->id)
                ->currentVersion()
                ->first(),
            'gradesToday' => $this->gradesToday($user),
            'gradeLimit' => $entitlements->limit($user, 'parlays_per_day'),
            /*
             * The engine's own recommendations, where it has any. These
             * are a shortcut and not the way in: with no market proven
             * this list is empty, and it used to be the only source of
             * legs - which is why the builder had nothing to click. Games
             * to bet on now come from Today's Games.
             */
            'candidates' => VlBoardEntry::query()->current()->sellablePicks()
                ->orderByDesc('edge_at_price')
                ->limit(20)
                ->get()
                ->reject(fn (VlBoardEntry $e) => $slip->has(ParlaySlip::keyFor(
                    (int) $e->edge_lab_event_id,
                    (string) $e->market,
                    $e->side,
                    $e->line,
                )))
                ->values(),
        ]);
    }

    /**
     * Put a selection on the slip.
     *
     * This used to require the selection be a pick from a market the
     * engine had proven, and refused everything else. With 24,610
     * candidates evaluated and none proven, that meant every click was
     * refused and the page rendered nothing to click in the first place.
     *
     * The engine declining to sell a pick is not a reason a customer
     * cannot record their own bet, so the gate is gone from here. It still
     * governs what VIGIL publishes as its own opinion, which is the only
     * thing it was ever protecting.
     *
     * What remains is the only check that matters: the market has to be
     * one we can price and settle, and it has to still be on offer.
     */
    public function add(Request $request, ParlaySlip $slip, GameMarkets $markets): RedirectResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'market' => ['required', 'string', Rule::in(GameMarkets::MARKETS)],
            'side' => ['required', 'string', 'max:8'],
            'line' => ['nullable', 'numeric'],
        ]);

        $selection = $markets->resolve(
            (int) $data['event_id'],
            (string) $data['market'],
            (string) $data['side'],
            $data['line'] ?? null,
        );

        /*
         * Said rather than swallowed. A line that moved between the page
         * loading and the click is ordinary, and a click that silently
         * does nothing is the one outcome worse than a refusal.
         */
        if ($selection === null) {
            return back()->withErrors([
                'slip' => 'That price is no longer on offer. Reload for the current market.',
            ]);
        }

        if (($refusal = $slip->add($selection)) !== null) {
            return back()->withErrors(['slip' => $refusal]);
        }

        return back();
    }

    public function remove(Request $request, ParlaySlip $slip): RedirectResponse
    {
        $slip->remove((string) $request->string('key'));

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

        if ($slip->count() < ParlayLedger::MINIMUM_LEGS) {
            return back()->withErrors(['slip' => 'A parlay needs at least two legs.']);
        }

        /*
         * Every leg carries the price the server read when it went on the
         * slip, so there is nothing unpriced to guard against here. A leg
         * without one could never have been added.
         */
        try {
            $parlay = $ledger->save(
                $request->user(),
                $slip->legsForLedger(),
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
