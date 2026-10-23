<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserBet;
use App\Models\VlBoardEntry;
use App\Services\Bets\Bankroll;
use App\Services\Bets\BetLedger;
use App\Services\Bets\BetRecord;
use App\Services\Entitlements\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * My Bets.
 *
 * Logs a bet. It never places one: Vigil holds no money, has no
 * sportsbook and settles nothing. The customer places the bet at their
 * own book and this records that they did, so their record, their ROI and
 * their closing line value have something to measure.
 */
class BetController extends Controller
{
    public function index(
        Request $request,
        BetRecord $record,
        Bankroll $bankroll,
        Entitlements $entitlements,
    ): View {
        $user = $request->user();

        return view('app.bets', [
            'bets' => UserBet::query()
                ->where('user_id', $user->id)
                ->orderByDesc('placed_at')
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
            'record' => $record->forUser($user),
            'curve' => $record->curve($user),
            // Closing line value is Pro. The figure is computed for
            // everybody - it costs nothing once the quote is stored - and
            // shown to those who pay for it.
            'showClv' => $entitlements->can($user, 'bets.clv'),
            'showRoi' => $entitlements->can($user, 'bets.roi'),
            'blocked' => $bankroll->blockedReason($user),
            'trackedLimit' => $entitlements->limit($user, 'tracked_bets'),
            'tracked' => UserBet::where('user_id', $user->id)->count(),
        ]);
    }

    /**
     * Log one, either from the board in a tap or typed in by hand.
     */
    public function store(
        Request $request,
        BetLedger $ledger,
        Bankroll $bankroll,
        Entitlements $entitlements,
    ): RedirectResponse {
        $user = $request->user();

        /*
         * The tracker cap, enforced here. §4 gives the free plan 25
         * bets; the 26th is refused with the reason rather than silently
         * dropped, and the customer keeps the 25 they have.
         */
        $limit = $entitlements->limit($user, 'tracked_bets');
        $used = UserBet::where('user_id', $user->id)->count();

        if ($limit !== null && $used >= $limit) {
            return back()->withErrors([
                'stake' => 'Your plan tracks '.$limit.' bets. Nothing has been deleted — a paid plan tracks every bet.',
            ]);
        }

        $data = $request->validate([
            'board_entry_id' => ['nullable', 'integer', 'exists:vl_board_entries,id'],
            /*
             * Dollars in, cents stored. A customer types 12.50 and the
             * product must not store 1250.0 as a float anywhere.
             *
             * Optional when logging from the board, because §8 asks for
             * one tap: the suggested stake is used instead, and if there
             * is no bankroll to size against the refusal says so.
             */
            'stake' => ['required_without:board_entry_id', 'nullable', 'numeric', 'min:0.01', 'max:100000'],

            // Only needed for a manual entry.
            'sport' => ['required_without:board_entry_id', 'nullable', 'string', 'max:20'],
            'market' => ['required_without:board_entry_id', 'nullable', 'string', 'max:40'],
            'selection' => ['required_without:board_entry_id', 'nullable', 'string', 'max:120'],
            'price_american' => ['required_without:board_entry_id', 'nullable', 'integer', 'between:-100000,100000'],
            'side' => ['nullable', Rule::in(['home', 'away', 'over', 'under'])],
            'line' => ['nullable', 'numeric', 'between:-999,999'],
            'book_title' => ['nullable', 'string', 'max:60'],
        ]);

        $entry = ($data['board_entry_id'] ?? null) === null
            ? null
            : VlBoardEntry::findOrFail($data['board_entry_id']);

        $stakeCents = $this->stakeCents($data, $entry, $user, $bankroll);

        if ($stakeCents === null) {
            return back()->withErrors([
                'stake' => 'Set a starting bankroll to log a bet in one tap, or type a stake.',
            ]);
        }

        if ($bankroll->exceedsCap($user, $stakeCents)) {
            return back()->withErrors([
                'stake' => 'That is more than your maximum stake of '
                    .$bankroll->settings($user)->max_stake_pct.'% of the bankroll.',
            ]);
        }

        try {
            if ($entry !== null) {
                $ledger->fromBoardEntry($user, $entry, $stakeCents);
            } else {
                $ledger->manual($user, [
                    'sport' => $data['sport'],
                    'market' => $data['market'],
                    'selection' => $data['selection'],
                    'side' => $data['side'] ?? null,
                    'line' => $data['line'] ?? null,
                    'price_american' => (int) $data['price_american'],
                    'book_title' => $data['book_title'] ?? null,
                    'stake_cents' => $stakeCents,
                ]);
            }
        } catch (RuntimeException $e) {
            // Carries the loss-limit message, the gate refusal and the
            // missing-price refusal. All three are things the customer
            // needs to read rather than a 500.
            return back()->withErrors(['stake' => $e->getMessage()]);
        }

        return back()->with('status', 'Logged. Nothing was placed or charged — '
            .config('brand.name').' holds no money and has no sportsbook.');
    }

    /**
     * The stake, typed or sized.
     *
     * A board bet with no stake takes the Kelly suggestion, which is
     * what makes one-tap logging possible without inventing a number -
     * and returns null rather than a default when there is no bankroll
     * to size against. Guessing $10 would be putting a figure in
     * somebody's record that they never chose.
     *
     * @param  array<string, mixed>  $data
     */
    private function stakeCents(array $data, ?VlBoardEntry $entry, $user, Bankroll $bankroll): ?int
    {
        if (($data['stake'] ?? null) !== null) {
            return (int) round(((float) $data['stake']) * 100);
        }

        if ($entry === null) {
            return null;
        }

        $suggested = $bankroll->suggestedStake(
            $user,
            $entry->model_prob === null ? null : (float) $entry->model_prob,
            (int) ($entry->best_price_american ?? 0),
        );

        return $suggested['stake_cents'] > 0 ? $suggested['stake_cents'] : null;
    }

    /**
     * The customer's own bets, as a CSV.
     *
     * §8: "CSV export of **own bets only**". Scoped in the query by user
     * id, so there is no variant of this that returns somebody else's
     * rows because a parameter was forgotten. There is also deliberately
     * no odds export here - §9 forbids it and a bet export is exactly
     * where one would get smuggled in.
     */
    public function export(Request $request, BetRecord $record): Response
    {
        $rows = $record->csvRows($request->user());

        $headers = $rows === []
            ? ['placed_at', 'sport', 'market', 'selection', 'stake', 'status']
            : array_keys($rows[0]);

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, $headers);

        foreach ($rows as $row) {
            fputcsv($csv, $row);
        }

        rewind($csv);
        $body = (string) stream_get_contents($csv);
        fclose($csv);

        $name = str(config('brand.short_name') ?: config('brand.name'))
            ->slug()
            ->append('-bets-', now()->toDateString(), '.csv')
            ->value();

        return response($body, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
