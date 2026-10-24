<?php

declare(strict_types=1);

use App\Enums\BetStatus;
use App\Enums\JobStatus;
use App\Enums\Market;
use App\Enums\Side;
use App\Jobs\Bets\SettleBets;
use App\Models\BankrollLedgerEntry;
use App\Models\Bet;
use App\Models\Event;
use App\Models\JobRun;
use App\Models\Team;
use App\Services\Engine\Bets\BetLedger;

function settledGame(int $home, int $away, string $status = 'final'): Event
{
    $n = Team::count();
    $homeTeam = Team::create(['sport' => 'nfl', 'name' => 'Home', 'display_name' => 'Home', 'slug' => 'home-'.$n]);
    $awayTeam = Team::create(['sport' => 'nfl', 'name' => 'Away', 'display_name' => 'Away', 'slug' => 'away-'.$n]);

    return Event::create([
        'sport' => 'nfl',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'starts_at' => now()->subHours(4),
        'status' => $status,
        'final_home_score' => $home,
        'final_away_score' => $away,
        'completed_at' => now()->subHour(),
    ]);
}

beforeEach(function () {
    $this->ledger = app(BetLedger::class);

    BankrollLedgerEntry::create([
        'entry_type' => 'deposit', 'amount_cents' => 500000,
        'balance_after_cents' => 500000, 'occurred_at' => now(), 'created_at' => now(),
    ]);
});

it('grades a finished bet without anyone opening the app', function () {
    $bet = $this->ledger->place(settledGame(27, 20), Market::Moneyline, Side::Home, 150, 10000);

    dispatch_sync(new SettleBets);

    // $100 at +150 returns $250.
    expect($bet->fresh()->status)->toBe(BetStatus::Won)
        ->and($bet->fresh()->pnl_cents)->toBe(15000)
        ->and(BankrollLedgerEntry::currentBalanceCents())->toBe(515000);
});

it('settles winners and losers on the same slate', function () {
    $game = settledGame(27, 20);

    $won = $this->ledger->place($game, Market::Spread, Side::Home, -110, 10000, line: -3.0);
    $lost = $this->ledger->place($game, Market::Total, Side::Under, -110, 10000, line: 44.5);

    dispatch_sync(new SettleBets);

    expect($won->fresh()->status)->toBe(BetStatus::Won)
        ->and($lost->fresh()->status)->toBe(BetStatus::Lost);
});

it('leaves a bet alone until its game is final', function () {
    $game = settledGame(0, 0, 'scheduled');
    $game->forceFill(['final_home_score' => null, 'final_away_score' => null, 'starts_at' => now()->addHours(3)])->save();

    $bet = $this->ledger->place($game, Market::Moneyline, Side::Home, -140, 10000);

    dispatch_sync(new SettleBets);

    expect($bet->fresh()->status)->toBe(BetStatus::Pending)
        ->and(BankrollLedgerEntry::currentBalanceCents())->toBe(490000);
});

it('returns the stake when the game was cancelled', function () {
    $game = settledGame(0, 0, 'canceled');
    $game->forceFill(['final_home_score' => null, 'final_away_score' => null])->save();

    $bet = $this->ledger->place($game, Market::Moneyline, Side::Home, -140, 10000);

    dispatch_sync(new SettleBets);

    expect($bet->fresh()->status)->toBe(BetStatus::Void)
        ->and(BankrollLedgerEntry::currentBalanceCents())->toBe(500000);
});

it('names the bets it could not decide instead of guessing', function () {
    // Final, but the source never delivered a score. A bet left pending is
    // fixable; a bet graded on a guess is a corrupted track record.
    $game = settledGame(0, 0);
    $game->forceFill(['final_home_score' => null, 'final_away_score' => null])->save();

    $bet = $this->ledger->place($game, Market::Moneyline, Side::Home, -140, 10000);

    dispatch_sync(new SettleBets);

    $run = JobRun::where('job_key', 'bets:settle')->latest('id')->first();

    expect($bet->fresh()->status)->toBe(BetStatus::Pending)
        ->and($run->status)->toBe(JobStatus::Success)
        ->and($run->context['undecidable_bet_ids'])->toContain($bet->id);
});

it('never settles the same bet twice', function () {
    $bet = $this->ledger->place(settledGame(27, 20), Market::Moneyline, Side::Home, 150, 10000);

    dispatch_sync(new SettleBets);
    dispatch_sync(new SettleBets);

    expect($bet->fresh()->events)->toHaveCount(2)
        ->and(BankrollLedgerEntry::currentBalanceCents())->toBe(515000);
});

it('records the run on the jobs screen', function () {
    $this->ledger->place(settledGame(27, 20), Market::Moneyline, Side::Home, 150, 10000);

    dispatch_sync(new SettleBets);

    $run = JobRun::where('job_key', 'bets:settle')->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(JobStatus::Success)
        ->and($run->records_out)->toBe(1);
});

it('skips a custom bet with no game behind it', function () {
    $bet = Bet::create([
        'is_custom' => true,
        'custom_description' => 'Heads',
        'price_american' => -110,
        'price_decimal' => 1.9091,
        'stake_cents' => 10000,
        'placed_at' => now(),
        'status' => BetStatus::Pending->value,
    ]);

    dispatch_sync(new SettleBets);

    expect($bet->fresh()->status)->toBe(BetStatus::Pending);
});
