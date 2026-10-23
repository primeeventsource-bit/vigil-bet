<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ParlayGrade;
use App\Models\User;
use App\Models\VigilRead;
use App\Models\VlBoardEntry;
use App\Services\Ai\ReadWriter;
use App\Services\Entitlements\Entitlements;
use App\Services\Parlay\ParlayJudge;
use App\Services\Parlay\ParlaySlip;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    private function gradesToday(User $user): int
    {
        return ParlayGrade::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', today())
            ->count();
    }
}
