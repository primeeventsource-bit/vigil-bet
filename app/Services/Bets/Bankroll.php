<?php

declare(strict_types=1);

namespace App\Services\Bets;

use App\Models\BankrollEntry;
use App\Models\BankrollSettings;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Staking, and the limits that stop it.
 *
 * §8 asks for Kelly sizing, stake caps, and daily and weekly loss limits
 * "with no override". Edge Lab's behaviour is ported deliberately: a
 * limit that can be lifted in the moment is not a limit. It is a dialog
 * that appears at the worst possible time and asks somebody who is losing
 * whether they would like to continue, and it will be answered yes.
 *
 * So limits tighten immediately and loosen only from tomorrow. There is
 * no confirmation step that bypasses one, and there is no code path in
 * this class that takes a "force" argument.
 */
class Bankroll
{
    /**
     * The customer's rules, with any due loosening applied.
     *
     * Applied on read rather than by a nightly job: a limit that stayed
     * tight because a scheduler was down would be a different kind of
     * broken promise, and this is cheap.
     */
    public function settings(User $user): BankrollSettings
    {
        $settings = BankrollSettings::firstOrCreate(['user_id' => $user->id]);

        if ($settings->pending_limits !== null
            && $settings->pending_limits_effective_on !== null
            && ! $settings->pending_limits_effective_on->isFuture()) {
            $settings->fill($settings->pending_limits);
            $settings->pending_limits = null;
            $settings->pending_limits_effective_on = null;
            $settings->save();
        }

        return $settings;
    }

    /**
     * Change the staking rules.
     *
     * Tightening is immediate. Loosening waits until tomorrow, which is
     * the whole of "no override": the decision is made by the person the
     * customer was yesterday rather than the one in front of a losing
     * afternoon.
     *
     * @param  array<string, mixed>  $changes
     * @return array{applied: array<string, mixed>, deferred: array<string, mixed>}
     */
    public function updateSettings(User $user, array $changes): array
    {
        $settings = $this->settings($user);

        $applied = [];
        $deferred = [];

        foreach ($changes as $key => $value) {
            if ($this->isLoosening($settings, $key, $value)) {
                $deferred[$key] = $value;

                continue;
            }

            $applied[$key] = $value;
        }

        if ($applied !== []) {
            $settings->fill($applied)->save();
        }

        if ($deferred !== []) {
            $settings->pending_limits = array_merge((array) $settings->pending_limits, $deferred);
            $settings->pending_limits_effective_on = today()->addDay();
            $settings->save();
        }

        return ['applied' => $applied, 'deferred' => $deferred];
    }

    /**
     * Whether a change gives the customer more rope.
     *
     * A null loss limit means no limit, so moving from a number to null
     * is the largest loosening there is and must not slip through as
     * "not a bigger number".
     */
    private function isLoosening(BankrollSettings $settings, string $key, mixed $value): bool
    {
        return match ($key) {
            'daily_loss_limit_cents', 'weekly_loss_limit_cents' => $value === null
                || ($settings->{$key} !== null && (int) $value > (int) $settings->{$key}),
            'max_stake_pct' => (float) $value > (float) $settings->max_stake_pct,
            'kelly_scale' => (float) $value > (float) $settings->kelly_scale,
            default => false,
        };
    }

    /** Starting bankroll plus every movement since. */
    public function balanceCents(User $user): int
    {
        return (int) $this->settings($user)->starting_cents
            + (int) BankrollEntry::where('user_id', $user->id)->sum('amount_cents');
    }

    /**
     * What the customer is down over a window, in cents, never negative.
     *
     * Read from the ledger rather than from settled bets, because a stake
     * that is still running is money that has left the bankroll. A limit
     * that only counted settled losses would let somebody stake their
     * way past it on a Sunday afternoon and find out on Monday.
     */
    public function lossSince(User $user, Carbon $from): int
    {
        $net = (int) BankrollEntry::where('user_id', $user->id)
            ->whereIn('kind', [BankrollEntry::STAKE, BankrollEntry::RETURN_])
            ->where('occurred_at', '>=', $from)
            ->sum('amount_cents');

        return max(0, -$net);
    }

    public function lossToday(User $user): int
    {
        return $this->lossSince($user, today()->startOfDay());
    }

    public function lossThisWeek(User $user): int
    {
        return $this->lossSince($user, today()->startOfWeek());
    }

    /**
     * Why the customer may not stake right now, or null if they may.
     *
     * A sentence rather than a boolean, because the answer has to be
     * shown to somebody and "blocked" is not an explanation. There is no
     * second argument that skips this.
     */
    public function blockedReason(User $user): ?string
    {
        $settings = $this->settings($user);

        $daily = $settings->daily_loss_limit_cents;
        $weekly = $settings->weekly_loss_limit_cents;

        if ($daily !== null && $this->lossToday($user) >= (int) $daily) {
            return 'You have reached the daily loss limit you set ('
                .$this->money((int) $daily).'). It lifts tomorrow.';
        }

        if ($weekly !== null && $this->lossThisWeek($user) >= (int) $weekly) {
            return 'You have reached the weekly loss limit you set ('
                .$this->money((int) $weekly).'). It lifts on Monday.';
        }

        return null;
    }

    public function mayStake(User $user): bool
    {
        return $this->blockedReason($user) === null;
    }

    /**
     * Kelly, scaled and capped.
     *
     * Full Kelly is only correct if the model's probability is exactly
     * right, and it never is - so the product offers a fraction of it,
     * defaults to a quarter, and applies a flat ceiling on top. The
     * ceiling is the part that matters: Kelly on a wrongly confident
     * probability recommends a stake that ruins somebody, and a cap
     * turns that from a disaster into a bad week.
     *
     * Returns zero for a bet with no edge rather than a small positive
     * number. There is no stake at which a negative-edge bet becomes a
     * good idea.
     *
     * @return array{stake_cents:int, kelly_fraction:float, capped:bool, reason:?string}
     */
    public function suggestedStake(User $user, ?float $modelProbability, int $priceAmerican): array
    {
        $none = ['stake_cents' => 0, 'kelly_fraction' => 0.0, 'capped' => false, 'reason' => null];

        if ($modelProbability === null || $modelProbability <= 0 || $modelProbability >= 1) {
            // array_merge, not `+`: a union keeps the left value, and
            // $none already carries a null reason.
            return array_merge($none, ['reason' => 'No model probability for this selection.']);
        }

        $balance = $this->balanceCents($user);

        if ($balance <= 0) {
            return array_merge($none, ['reason' => 'Set a starting bankroll to see a suggested stake.']);
        }

        // Decimal odds minus one: what a unit stake wins.
        $b = $priceAmerican > 0
            ? $priceAmerican / 100
            : 100 / abs($priceAmerican);

        $kelly = ($modelProbability * $b - (1 - $modelProbability)) / $b;

        if ($kelly <= 0) {
            return array_merge($none, [
                'reason' => 'The model does not rate this price. Kelly says stake nothing.',
            ]);
        }

        $settings = $this->settings($user);

        $scaled = $kelly * (float) $settings->kelly_scale;
        $capFraction = (float) $settings->max_stake_pct / 100;
        $capped = $scaled > $capFraction;

        $fraction = min($scaled, $capFraction);

        return [
            'stake_cents' => (int) round($balance * $fraction),
            'kelly_fraction' => round($kelly, 5),
            'capped' => $capped,
            'reason' => $capped
                ? 'Kelly suggested more than your '.$settings->max_stake_pct.'% cap, so the cap applies.'
                : null,
        ];
    }

    /**
     * Whether a stake is within the cap, independent of what Kelly said.
     *
     * Checked on the way in as well as suggested on the way out, because
     * a customer types their own number and the cap is not a hint.
     */
    public function exceedsCap(User $user, int $stakeCents): bool
    {
        $balance = $this->balanceCents($user);

        if ($balance <= 0) {
            return false;
        }

        return $stakeCents > (int) round($balance * (float) $this->settings($user)->max_stake_pct / 100);
    }

    /** @param  array<string, mixed>  $attributes */
    public function record(User $user, string $kind, int $amountCents, array $attributes = []): BankrollEntry
    {
        return BankrollEntry::create([
            'user_id' => $user->id,
            'kind' => $kind,
            'amount_cents' => $amountCents,
            'user_bet_id' => $attributes['user_bet_id'] ?? null,
            'user_parlay_id' => $attributes['user_parlay_id'] ?? null,
            'reason' => $attributes['reason'] ?? null,
            'occurred_at' => $attributes['occurred_at'] ?? now(),
            'created_at' => now(),
        ]);
    }

    private function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }
}
