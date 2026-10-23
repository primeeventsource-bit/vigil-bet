<?php

declare(strict_types=1);

use App\Models\BankrollEntry;
use App\Models\User;
use App\Models\UserBet;
use App\Services\Bets\Bankroll;
use App\Services\Bets\BetLedger;

function withBankroll(int $startingCents = 100_000, array $settings = []): User
{
    $user = betUser();

    app(Bankroll::class)->settings($user)->update(array_merge([
        'starting_cents' => $startingCents,
    ], $settings));

    return $user;
}

/* ----------------------------------------------------------------------
 | Kelly and the cap
 ---------------------------------------------------------------------- */

it('sizes a stake from the edge, scaled and capped', function () {
    // 62% at -110 is a real edge. Full Kelly on it is about 20% of the
    // bankroll, which is why nothing here stakes full Kelly.
    $user = withBankroll(100_000, ['kelly_scale' => 0.25, 'max_stake_pct' => 2.0]);

    $result = app(Bankroll::class)->suggestedStake($user, 0.62, -110);

    expect($result['kelly_fraction'])->toBeGreaterThan(0.15)
        // A quarter of that is still over the 2% cap, so the cap wins.
        ->and($result['capped'])->toBeTrue()
        ->and($result['stake_cents'])->toBe(2_000)
        ->and($result['reason'])->toContain('cap');
});

it('stakes nothing on a bet with no edge', function () {
    $user = withBankroll();

    // 50% at -110 loses to the vig. There is no stake at which that
    // becomes a good idea.
    $result = app(Bankroll::class)->suggestedStake($user, 0.50, -110);

    expect($result['stake_cents'])->toBe(0)
        ->and($result['reason'])->toContain('stake nothing');
});

it('says so rather than suggesting a stake when there is no bankroll', function () {
    $result = app(Bankroll::class)->suggestedStake(betUser(), 0.62, -110);

    expect($result['stake_cents'])->toBe(0)
        ->and($result['reason'])->toContain('starting bankroll');
});

it('says so rather than guessing when there is no model probability', function () {
    $result = app(Bankroll::class)->suggestedStake(withBankroll(), null, -110);

    expect($result['stake_cents'])->toBe(0)
        ->and($result['reason'])->toContain('No model probability');
});

it('scales with the fraction the customer chose', function () {
    $quarter = app(Bankroll::class)->suggestedStake(
        withBankroll(100_000, ['kelly_scale' => 0.25, 'max_stake_pct' => 10]), 0.62, -110);

    $tenth = app(Bankroll::class)->suggestedStake(
        withBankroll(100_000, ['kelly_scale' => 0.10, 'max_stake_pct' => 10]), 0.62, -110);

    expect($quarter['stake_cents'])->toBeGreaterThan($tenth['stake_cents']);
});

it('refuses a typed stake over the cap', function () {
    $user = withBankroll(100_000, ['max_stake_pct' => 2.0]);

    // The cap is not a hint: the customer types their own number and it
    // is checked on the way in.
    $this->actingAs($user)
        ->post(route('bets.store'), [
            'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Bills',
            'price_american' => -110, 'stake' => 50,
        ])
        ->assertSessionHasErrors('stake');

    expect(UserBet::count())->toBe(0);
    expect(session('errors')->first('stake'))->toContain('maximum stake');
});

it('allows a stake at the cap', function () {
    $user = withBankroll(100_000, ['max_stake_pct' => 2.0]);

    $this->actingAs($user)
        ->post(route('bets.store'), [
            'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Bills',
            'price_american' => -110, 'stake' => 20,
        ])
        ->assertSessionHasNoErrors();

    expect(UserBet::count())->toBe(1);
});

/* ----------------------------------------------------------------------
 | Loss limits, with no override
 ---------------------------------------------------------------------- */

it('counts a running stake against the loss limit, not just a settled loss', function () {
    $user = withBankroll(100_000, ['daily_loss_limit_cents' => 5_000]);

    app(BetLedger::class)->manual($user, [
        'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Bills',
        'price_american' => -110, 'stake_cents' => 5_000,
    ]);

    // A limit that only counted settled losses would let somebody stake
    // their way past it on a Sunday and find out on Monday.
    expect(app(Bankroll::class)->lossToday($user))->toBe(5_000)
        ->and(app(Bankroll::class)->mayStake($user))->toBeFalse();
});

it('refuses the next bet once the daily limit is reached, with the reason', function () {
    $user = withBankroll(100_000, ['daily_loss_limit_cents' => 2_000, 'max_stake_pct' => 10]);

    app(BetLedger::class)->manual($user, [
        'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'First',
        'price_american' => -110, 'stake_cents' => 2_000,
    ]);

    $this->actingAs($user)
        ->post(route('bets.store'), [
            'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Second',
            'price_american' => -110, 'stake' => 5,
        ])
        ->assertSessionHasErrors('stake');

    expect(UserBet::count())->toBe(1);
    expect(session('errors')->first('stake'))->toContain('daily loss limit');
});

it('lets a winner put the customer back under the limit', function () {
    $user = withBankroll(100_000, ['daily_loss_limit_cents' => 2_000, 'max_stake_pct' => 10]);

    $bet = app(BetLedger::class)->manual($user, [
        'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'First',
        'price_american' => 100, 'stake_cents' => 2_000,
    ]);

    expect(app(Bankroll::class)->mayStake($user))->toBeFalse();

    app(BetLedger::class)->settle($bet, 'won');

    // The limit is about being down, not about having staked. Somebody
    // who is even is not in a hole.
    expect(app(Bankroll::class)->lossToday($user))->toBe(0)
        ->and(app(Bankroll::class)->mayStake($user))->toBeTrue();
});

it('applies a tighter limit immediately', function () {
    $user = withBankroll(100_000, ['daily_loss_limit_cents' => 50_000]);

    $this->actingAs($user)->post(route('bankroll.update'), [
        'kelly_scale' => 0.25,
        'max_stake_pct' => 2,
        'daily_loss_limit' => 100,
        'weekly_loss_limit' => '',
    ])->assertSessionHasNoErrors();

    // Tightening is always allowed at once. The whole asymmetry exists
    // to make stopping easy and continuing hard.
    expect(app(Bankroll::class)->settings($user->refresh())->daily_loss_limit_cents)->toBe(10_000);
});

it('makes a looser limit wait until tomorrow', function () {
    $user = withBankroll(100_000, ['daily_loss_limit_cents' => 10_000]);

    $this->actingAs($user)->post(route('bankroll.update'), [
        'kelly_scale' => 0.25,
        'max_stake_pct' => 2,
        'daily_loss_limit' => 500,
        'weekly_loss_limit' => '',
    ]);

    $settings = app(Bankroll::class)->settings($user->refresh());

    // A limit a customer can lift mid-session is not a limit. It is a
    // dialog that appears at the worst possible moment and gets
    // answered yes.
    expect($settings->daily_loss_limit_cents)->toBe(10_000)
        ->and($settings->hasPendingChange())->toBeTrue();

    expect(session('status'))->toContain('tomorrow');
});

it('applies the looser limit once tomorrow arrives', function () {
    $user = withBankroll(100_000, ['daily_loss_limit_cents' => 10_000]);

    app(Bankroll::class)->updateSettings($user, ['daily_loss_limit_cents' => 50_000]);

    $this->travel(1)->day();

    // Applied on read rather than by a nightly job: a limit that stayed
    // tight because a scheduler was down would be a different kind of
    // broken promise.
    expect(app(Bankroll::class)->settings($user->refresh())->daily_loss_limit_cents)->toBe(50_000);
});

it('treats removing a limit entirely as the largest loosening there is', function () {
    $user = withBankroll(100_000, ['daily_loss_limit_cents' => 10_000]);

    app(Bankroll::class)->updateSettings($user, ['daily_loss_limit_cents' => null]);

    // Otherwise null slips through as "not a bigger number" and the
    // limit disappears on the spot.
    expect(app(Bankroll::class)->settings($user->refresh())->daily_loss_limit_cents)->toBe(10_000);
});

it('has no route or parameter anywhere that lifts a limit now', function () {
    $user = withBankroll(100_000, ['daily_loss_limit_cents' => 1_000]);

    app(BetLedger::class)->manual($user, [
        'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'First',
        'price_american' => -110, 'stake_cents' => 1_000,
    ]);

    // Every shape of "please let me through" a client might try.
    foreach ([['force' => true], ['override' => 1], ['confirm' => 'yes'], ['acknowledge_risk' => true]] as $attempt) {
        $this->actingAs($user)
            ->post(route('bets.store'), array_merge([
                'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Again',
                'price_american' => -110, 'stake' => 5,
            ], $attempt))
            ->assertSessionHasErrors('stake');
    }

    expect(UserBet::count())->toBe(1);
});

/* ----------------------------------------------------------------------
 | The ledger
 ---------------------------------------------------------------------- */

it('keeps the balance as the sum of the ledger', function () {
    $user = withBankroll(50_000);
    $bankroll = app(Bankroll::class);

    $bankroll->record($user, BankrollEntry::DEPOSIT, 20_000);
    $bankroll->record($user, BankrollEntry::WITHDRAWAL, -5_000);

    expect($bankroll->balanceCents($user))->toBe(65_000);
});

it('never rewrites a ledger entry', function () {
    $user = withBankroll();

    $entry = app(Bankroll::class)->record($user, BankrollEntry::DEPOSIT, 1_000);

    expect(fn () => $entry->update(['amount_cents' => 999_999]))
        ->toThrow(RuntimeException::class, 'append-only');
});

it('keeps the bankroll page behind pro', function () {
    $this->actingAs(betUser(null))->get(route('bankroll'))
        ->assertRedirect(route('upgrade', ['feature' => 'bankroll']));

    $this->actingAs(betUser('pro'))->get(route('bankroll'))->assertOk();
});
