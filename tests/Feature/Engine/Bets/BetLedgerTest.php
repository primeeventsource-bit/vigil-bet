<?php

declare(strict_types=1);

use App\Enums\BetEventType;
use App\Enums\BetStatus;
use App\Enums\Market;
use App\Enums\Side;
use App\Exceptions\AppendOnlyViolation;
use App\Models\BankrollLedgerEntry;
use App\Models\BetEvent;
use App\Models\Event;
use App\Models\Team;
use App\Services\Engine\Bets\BetLedger;

function anEvent(): Event
{
    // Unique slugs: a test that places two bets builds two events, and the
    // natural key would otherwise collide.
    $n = Team::count();
    $home = Team::create(['sport' => 'nfl', 'name' => 'Buffalo Bills', 'display_name' => 'Buffalo Bills', 'slug' => 'buffalo-bills-'.$n]);
    $away = Team::create(['sport' => 'nfl', 'name' => 'Miami Dolphins', 'display_name' => 'Miami Dolphins', 'slug' => 'miami-dolphins-'.$n]);

    return Event::create([
        'sport' => 'nfl', 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'starts_at' => now()->addHours(4), 'status' => 'scheduled',
    ]);
}

beforeEach(function () {
    $this->ledger = app(BetLedger::class);

    BankrollLedgerEntry::create([
        'entry_type' => 'deposit', 'amount_cents' => 500000,
        'balance_after_cents' => 500000, 'occurred_at' => now(), 'created_at' => now(),
    ]);
});

it('records a bet and reserves the stake', function () {
    $bet = $this->ledger->place(anEvent(), Market::Moneyline, Side::Home, -140, 10000);

    expect($bet->status)->toBe(BetStatus::Pending)
        ->and($bet->stake_cents)->toBe(10000)
        ->and(BankrollLedgerEntry::currentBalanceCents())->toBe(490000);
});

it('stores the price exactly as entered', function () {
    // Whatever the app thought the price was, the slip is the truth.
    $bet = $this->ledger->place(anEvent(), Market::Moneyline, Side::Home, -115, 10000);

    expect($bet->price_american)->toBe(-115)
        ->and($bet->price_decimal)->toEqualWithDelta(1.8696, 0.0001);
});

it('writes an append-only placed event', function () {
    $bet = $this->ledger->place(anEvent(), Market::Moneyline, Side::Home, -140, 10000);

    $event = BetEvent::where('bet_id', $bet->id)->first();

    expect($event->type)->toBe(BetEventType::Placed)
        ->and($event->sequence)->toBe(1)
        ->and(fn () => $event->update(['sequence' => 2]))
        ->toThrow(AppendOnlyViolation::class);
});

it('pays out a winner at the price taken', function () {
    $bet = $this->ledger->place(anEvent(), Market::Moneyline, Side::Home, 150, 10000);

    $this->ledger->grade($bet, BetStatus::Won);

    // $100 at +150 returns $250, a $150 profit.
    expect($bet->fresh()->payout_cents)->toBe(25000)
        ->and($bet->fresh()->pnl_cents)->toBe(15000)
        ->and(BankrollLedgerEntry::currentBalanceCents())->toBe(515000);
});

it('keeps the stake on a loser', function () {
    $bet = $this->ledger->place(anEvent(), Market::Moneyline, Side::Home, -140, 10000);

    $this->ledger->grade($bet, BetStatus::Lost);

    expect($bet->fresh()->pnl_cents)->toBe(-10000)
        ->and(BankrollLedgerEntry::currentBalanceCents())->toBe(490000);
});

it('returns the stake on a push', function () {
    $bet = $this->ledger->place(anEvent(), Market::Spread, Side::Home, -110, 10000, line: -3.0);

    $this->ledger->grade($bet, BetStatus::Push);

    expect($bet->fresh()->pnl_cents)->toBe(0)
        ->and(BankrollLedgerEntry::currentBalanceCents())->toBe(500000);
});

it('leaves the bankroll alone for a paper bet', function () {
    $bet = $this->ledger->place(anEvent(), Market::Moneyline, Side::Home, -140, 10000, isPaper: true);

    $this->ledger->grade($bet, BetStatus::Won);

    // Paper bets build a record without risking anything, which is what
    // makes them worth keeping separate.
    expect(BankrollLedgerEntry::currentBalanceCents())->toBe(500000)
        ->and($bet->fresh()->pnl_cents)->toBeGreaterThan(0);
});

it('refuses to settle the same bet twice', function () {
    $bet = $this->ledger->place(anEvent(), Market::Moneyline, Side::Home, -140, 10000);
    $this->ledger->grade($bet, BetStatus::Won);

    expect(fn () => $this->ledger->grade($bet->fresh(), BetStatus::Lost))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses an impossible price or stake', function () {
    expect(fn () => $this->ledger->place(anEvent(), Market::Moneyline, Side::Home, -50, 10000))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->ledger->place(anEvent(), Market::Moneyline, Side::Home, -140, 0))
        ->toThrow(InvalidArgumentException::class);
});

it('leaves a full audit trail across the life of a bet', function () {
    $bet = $this->ledger->place(anEvent(), Market::Moneyline, Side::Home, -140, 10000);
    $this->ledger->grade($bet, BetStatus::Won);

    $events = BetEvent::where('bet_id', $bet->id)->orderBy('sequence')->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->type)->toBe(BetEventType::Placed)
        ->and($events[1]->type)->toBe(BetEventType::Graded);
});
