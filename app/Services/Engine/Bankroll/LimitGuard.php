<?php

declare(strict_types=1);

namespace App\Services\Engine\Bankroll;

use App\Enums\BetStatus;
use App\Enums\LimitPeriod;
use App\Models\BankrollSetting;
use App\Models\Bet;
use App\Models\LossLimitPeriod;
use App\Support\Clock;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * The self-imposed limits.
 *
 * These exist to be obeyed on the day they are least welcome, so the lock is
 * one-way: once a period is locked it stays locked until the period rolls
 * over on its own. Nothing in the app unlocks it early, and there is
 * deliberately no override.
 */
class LimitGuard
{
    /**
     * The window a limit is measured over, in the bettor's own timezone.
     *
     * The betting day is the bettor's day, not the server's. The app clock
     * runs on UTC, so a window cut there rolls over at eight in the evening
     * Eastern - mid-card, with the primetime game still to come - and a
     * lockout earned in the afternoon would clear itself at exactly the
     * hour it is least welcome and most easily spent. That is an override
     * by the calendar, and the point of this class is that there is none.
     *
     * One definition, used by the guard and asserted against by its tests,
     * because a second copy of it is how the page and the guard come to
     * disagree about what day it is.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function window(LimitPeriod $type, ?Carbon $now = null): array
    {
        $local = ($now ?? now())->copy()
            ->timezone(Clock::zone());

        return $type === LimitPeriod::Daily
            ? [$local->copy()->startOfDay(), $local->copy()->endOfDay()]
            : [$local->copy()->startOfWeek(), $local->copy()->endOfWeek()];
    }

    public function currentPeriod(LimitPeriod $type, ?BankrollSetting $settings = null, ?Carbon $now = null): LossLimitPeriod
    {
        $now ??= now();
        $settings ??= BankrollSetting::current();

        [$start, $end] = $this->window($type, $now);

        $limitCents = $type === LimitPeriod::Daily
            ? (int) ($settings?->daily_loss_limit_cents ?? 0)
            : (int) ($settings?->weekly_loss_limit_cents ?? 0);

        // Match on the same Carbon value that gets written, not on a date
        // string: the column is a date but the driver stores a datetime, so
        // a string lookup silently misses and collides on the unique index.
        $period = LossLimitPeriod::query()->firstOrCreate(
            ['period_type' => $type->value, 'period_start' => $start->copy()->startOfDay()],
            ['period_end' => $end->copy()->startOfDay(), 'limit_cents' => $limitCents],
        );

        return $this->refresh($period, $start, $end);
    }

    public function isLocked(?Carbon $now = null): bool
    {
        foreach (LimitPeriod::cases() as $type) {
            if ($this->currentPeriod($type, now: $now)->isLocked()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, LossLimitPeriod> the periods currently locked */
    public function lockedPeriods(?Carbon $now = null): array
    {
        return array_values(array_filter(
            array_map(fn (LimitPeriod $t) => $this->currentPeriod($t, now: $now), LimitPeriod::cases()),
            fn (LossLimitPeriod $p) => $p->isLocked(),
        ));
    }

    /**
     * Headroom left under the daily exposure cap. Exposure is money at risk
     * today, not money lost: a cap on how much of the bankroll can be in play
     * at once.
     */
    public function remainingDailyExposureCents(int $bankrollCents, ?BankrollSetting $settings = null, ?Carbon $now = null): int
    {
        $settings ??= BankrollSetting::current();

        if ($settings === null) {
            return 0;
        }

        if ($this->isLocked($now)) {
            return 0;
        }

        $cap = Money::pctOf($bankrollCents, $settings->max_daily_exposure_pct);
        $used = $this->currentPeriod(LimitPeriod::Daily, $settings, $now)->exposure_cents;

        return max(0, $cap - $used);
    }

    /** Recomputes realised P/L and exposure, and locks the period if the limit is spent. */
    private function refresh(LossLimitPeriod $period, Carbon $start, Carbon $end): LossLimitPeriod
    {
        // placed_at is stored in UTC, and a Carbon binds as whatever wall
        // time its own zone reads, so the local window has to be converted
        // rather than handed over as it stands.
        $bets = Bet::query()
            ->real()
            ->whereBetween('placed_at', [$start->copy()->utc(), $end->copy()->utc()]);

        $staked = (int) (clone $bets)->sum('stake_cents');
        $pnl = (int) (clone $bets)->sum('pnl_cents');
        $exposure = (int) (clone $bets)->where('status', BetStatus::Pending)->sum('stake_cents');

        $attributes = [
            'realized_pnl_cents' => $pnl,
            'staked_cents' => $staked,
            'exposure_cents' => $exposure,
        ];

        // One way. A period that has been locked stays locked.
        if ($period->locked_at === null && $period->limit_cents > 0 && $pnl <= -$period->limit_cents) {
            $attributes['locked_at'] = now();
            $attributes['lock_reason'] = 'loss_limit_reached';
        }

        $period->forceFill($attributes)->save();

        return $period;
    }
}
