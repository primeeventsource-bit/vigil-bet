<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BankrollEntry;
use App\Services\Bets\Bankroll;
use App\Services\Bets\BetRecord;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Bankroll: Kelly sizing, a stake cap, and loss limits that do not
 * negotiate.
 *
 * The one thing to understand about this controller is what it does not
 * have: a way to lift a loss limit now. §8 says "with no override" and
 * Edge Lab's behaviour is ported deliberately. A limit a customer can
 * raise in the moment is not a limit - it is a dialog that appears at the
 * worst possible time and asks somebody who is losing whether they would
 * like to continue, and it gets answered yes.
 *
 * So tightening is immediate and loosening takes effect tomorrow. There
 * is no confirm-and-proceed, no "I understand the risks" checkbox, and no
 * parameter anywhere below that skips the check.
 */
class BankrollController extends Controller
{
    public function show(Request $request, Bankroll $bankroll, BetRecord $record): View
    {
        $user = $request->user();
        $settings = $bankroll->settings($user);

        return view('app.bankroll', [
            'settings' => $settings,
            'balanceCents' => $bankroll->balanceCents($user),
            'lossTodayCents' => $bankroll->lossToday($user),
            'lossWeekCents' => $bankroll->lossThisWeek($user),
            'blocked' => $bankroll->blockedReason($user),
            'record' => $record->forUser($user),
            'entries' => BankrollEntry::query()
                ->where('user_id', $user->id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(30)
                ->get(),
        ]);
    }

    public function update(Request $request, Bankroll $bankroll): RedirectResponse
    {
        $data = $request->validate([
            // Full Kelly is only right if the probability is exactly
            // right, so the scale is capped below 1 - there is no
            // setting here that stakes the mathematically optimal amount
            // on a number that is estimated.
            'kelly_scale' => ['required', 'numeric', 'min:0.05', 'max:0.5'],
            'max_stake_pct' => ['required', 'numeric', 'min:0.1', 'max:10'],
            'daily_loss_limit' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'weekly_loss_limit' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $user = $request->user();
        $before = $bankroll->settings($user)->only([
            'kelly_scale', 'max_stake_pct', 'daily_loss_limit_cents', 'weekly_loss_limit_cents',
        ]);

        $result = $bankroll->updateSettings($user, [
            'kelly_scale' => (float) $data['kelly_scale'],
            'max_stake_pct' => (float) $data['max_stake_pct'],
            'daily_loss_limit_cents' => $this->cents($data['daily_loss_limit'] ?? null),
            'weekly_loss_limit_cents' => $this->cents($data['weekly_loss_limit'] ?? null),
        ]);

        // A customer changing their own limits is not an administrative
        // write, but it is the one setting whose history somebody will
        // eventually need to read back.
        Audit::write('bankroll.limits_changed', $user, $before, $result['applied'] + $result['deferred'],
            $result['deferred'] === [] ? 'Tightened, applied now' : 'Loosened, takes effect tomorrow');

        return back()->with('status', $result['deferred'] === []
            ? 'Saved.'
            : 'Saved. The looser limits take effect tomorrow — a limit you can lift mid-session is not a limit.');
    }

    /** Record money in or out of the bankroll. */
    public function funds(Request $request, Bankroll $bankroll): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:deposit,withdrawal,starting'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
        ]);

        $user = $request->user();
        $cents = (int) round(((float) $data['amount']) * 100);

        if ($data['kind'] === 'starting') {
            $bankroll->settings($user)->update(['starting_cents' => $cents]);

            return back()->with('status', 'Starting bankroll set.');
        }

        $bankroll->record(
            $user,
            $data['kind'] === 'deposit' ? BankrollEntry::DEPOSIT : BankrollEntry::WITHDRAWAL,
            $data['kind'] === 'deposit' ? $cents : -$cents,
            ['reason' => 'Recorded by the customer'],
        );

        return back()->with('status', 'Recorded. '.config('brand.name')
            .' holds no money — this is your own note of what is at your books.');
    }

    private function cents(mixed $dollars): ?int
    {
        return $dollars === null || $dollars === ''
            ? null
            : (int) round(((float) $dollars) * 100);
    }
}
