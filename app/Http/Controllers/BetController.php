<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VlBoardEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Logs a bet the customer placed. It never places one.
 *
 * The button on the dashboard says "Bet", which is the design's word.
 * What it does is record that this customer backed this selection at
 * this price, so their own record, their ROI and their closing line
 * value have something to measure. Vigil holds no money, has no
 * sportsbook and settles nothing - the bet itself is placed by the
 * customer at their own book, and there is nothing here that could do
 * otherwise.
 *
 * The write itself lands with My Bets at step 8. Until then this refuses
 * honestly rather than pretending to have saved something.
 */
class BetController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'board_entry_id' => ['required', 'integer', 'exists:vl_board_entries,id'],
        ]);

        $entry = VlBoardEntry::findOrFail($validated['board_entry_id']);

        /*
         * The gate, again, on the way in. A customer should not be able
         * to log a bet against a pick the product was never allowed to
         * show - and a board entry id is a number anybody can type.
         */
        if (! $entry->isSellablePick()) {
            return back()->with('error', 'That selection is not a pick from a proven market.');
        }

        return back()->with('status', 'Bet logging arrives with My Bets at step 8. Nothing was placed or charged.');
    }
}
