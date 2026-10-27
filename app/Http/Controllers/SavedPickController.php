<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Opinion;
use App\Models\SavedPick;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * My Picks: published opinions a customer has kept.
 *
 * Distinct from My Bets, which is money. Nothing here is staked, nothing
 * here claims a profit, and the result shown against a saved pick is the
 * opinion's own graded result rather than a second copy that could
 * disagree with the public record.
 *
 * Ungated. A shortlist of things the product already showed this
 * customer is not a feature to sell them, and gating it would mean a
 * downgrade silently emptying a list somebody built.
 *
 * Every query is scoped to the signed-in user. That is the whole of the
 * isolation the spec asks for on this page, and it is asserted in the
 * tests rather than left to the fact that nobody would think to try.
 */
class SavedPickController extends Controller
{
    public function index(Request $request): View
    {
        $saved = SavedPick::query()
            ->where('user_id', $request->user()->getKey())
            ->with(['opinion.result', 'opinion.event'])
            ->latest()
            ->paginate(30);

        return view('app.my-picks', [
            'saved' => $saved,
            /*
             * Counted from the same scoped set rather than queried
             * again, so the headline and the list can never describe
             * different things.
             */
            'awaiting' => $saved->getCollection()
                ->filter(fn (SavedPick $pick) => $pick->opinion?->result === null)
                ->count(),
        ]);
    }

    public function store(Request $request, Opinion $opinion): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        /*
         * firstOrCreate rather than create: saving twice is one
         * intention expressed twice, and the unique index would
         * otherwise turn the second click into a 500.
         */
        SavedPick::firstOrCreate(
            [
                'user_id' => $request->user()->getKey(),
                'opinion_id' => $opinion->getKey(),
            ],
            ['note' => $data['note'] ?? null]
        );

        return back()->with('status', 'Saved to My Picks.');
    }

    public function destroy(Request $request, Opinion $opinion): RedirectResponse
    {
        // Scoped by user id as well as opinion id. Deleting by the
        // opinion alone would let one customer clear another's list.
        SavedPick::query()
            ->where('user_id', $request->user()->getKey())
            ->where('opinion_id', $opinion->getKey())
            ->delete();

        return back()->with('status', 'Removed from My Picks.');
    }
}
