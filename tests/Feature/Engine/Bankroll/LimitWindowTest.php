<?php

declare(strict_types=1);

use App\Enums\LimitPeriod;
use App\Enums\Market;
use App\Enums\Side;
use App\Modeling\Odds\OddsConverter;
use App\Models\BankrollSetting;
use App\Models\Bet;
use App\Services\Engine\Bankroll\LimitGuard;
use Illuminate\Support\Carbon;

/*
 * The betting day belongs to the bettor, not the server.
 *
 * The app clock runs on UTC and the bettor is on Eastern time, so a window
 * cut on the server's calendar turns over at eight in the evening - with
 * the primetime game still to come. A daily loss limit that resets there
 * is an override by the calendar, handed out on the evening it is least
 * welcome. These tests sit on both sides of that instant.
 */

function limits(int $daily = 50000): BankrollSetting
{
    return BankrollSetting::create([
        'starting_bankroll_cents' => 500000,
        'unit_size_cents' => 10000,
        'min_stake_cents' => 5000,
        'max_stake_cents' => 100000,
        'kelly_fraction' => 0.25,
        'max_stake_pct' => 2.0,
        'max_daily_exposure_pct' => 6.0,
        'daily_loss_limit_cents' => $daily,
        'weekly_loss_limit_cents' => 999999999,
        'edge_threshold' => 0.03,
        'effective_from' => now()->subMonth(),
    ]);
}

function settledLoss(Carbon $placedAt, int $stake = 60000): Bet
{
    return Bet::create([
        'sport' => 'nfl',
        'market' => Market::Moneyline->value,
        'side' => Side::Home->value,
        'price_american' => -110,
        'price_decimal' => OddsConverter::americanToDecimal(-110),
        'stake_cents' => $stake,
        'pnl_cents' => -$stake,
        'status' => 'lost',
        'is_paper' => false,
        'is_custom' => true,
        'placed_at' => $placedAt,
        'settled_at' => $placedAt->copy()->addHour(),
    ]);
}

it('keeps a lockout shut across the server midnight that falls mid-evening', function () {
    limits(daily: 50000);

    // An afternoon loss past the daily limit: 3pm Eastern, still 19:00 UTC.
    $afternoon = Carbon::parse('2026-10-21 19:00:00', 'UTC');
    settledLoss($afternoon);

    $guard = app(LimitGuard::class);

    expect($guard->isLocked($afternoon->copy()->addMinutes(30)))->toBeTrue();

    // 9pm Eastern. The server calendar has already turned over to the 22nd,
    // but the bettor is mid-evening on the 21st and the limit is spent.
    $primetime = Carbon::parse('2026-10-22 01:00:00', 'UTC');

    expect($guard->isLocked($primetime))->toBeTrue();
});

it('counts an evening loss against the day the bettor is actually in', function () {
    limits(daily: 50000);

    // 8:30pm Eastern on the 21st, which the server calls the 22nd.
    $evening = Carbon::parse('2026-10-22 00:30:00', 'UTC');
    settledLoss($evening);

    $period = app(LimitGuard::class)->currentPeriod(LimitPeriod::Daily, now: $evening);

    expect($period->realized_pnl_cents)->toBe(-60000)
        ->and($period->period_start->toDateString())->toBe('2026-10-21')
        ->and($period->isLocked())->toBeTrue();
});

it('opens a fresh day only when the bettor day turns over', function () {
    limits(daily: 50000);

    settledLoss(Carbon::parse('2026-10-21 19:00:00', 'UTC'));

    $guard = app(LimitGuard::class);

    // 1am Eastern on the 22nd: a new day for the bettor, and a clean sheet.
    $next = Carbon::parse('2026-10-22 05:00:00', 'UTC');
    $period = $guard->currentPeriod(LimitPeriod::Daily, now: $next);

    expect($period->period_start->toDateString())->toBe('2026-10-22')
        ->and($period->realized_pnl_cents)->toBe(0)
        ->and($period->isLocked())->toBeFalse();
});

it('measures exposure over the bettor day too', function () {
    limits();

    // Placed 4pm Eastern, still pending when the server date has rolled.
    Bet::create([
        'sport' => 'nfl',
        'market' => Market::Moneyline->value,
        'side' => Side::Home->value,
        'price_american' => -110,
        'price_decimal' => OddsConverter::americanToDecimal(-110),
        'stake_cents' => 25000,
        'status' => 'pending',
        'is_paper' => false,
        'is_custom' => true,
        'placed_at' => Carbon::parse('2026-10-21 20:00:00', 'UTC'),
    ]);

    $primetime = Carbon::parse('2026-10-22 01:00:00', 'UTC');

    // 6% of $5,000 is $300, of which $250 is already in play.
    expect(app(LimitGuard::class)->remainingDailyExposureCents(500000, now: $primetime))
        ->toBe(5000);
});
