<?php

declare(strict_types=1);

use App\Models\BankrollEntry;
use App\Models\UserBet;
use App\Models\UserBetEvent;
use App\Services\Bets\Bankroll;
use App\Services\Bets\BetLedger;

it('logs a bet and never places one', function () {
    $user = betUser();

    $this->actingAs($user)
        ->post(route('bets.store'), [
            'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Bills',
            'side' => 'home', 'price_american' => -110, 'stake' => 25,
        ])
        ->assertRedirect();

    $bet = UserBet::sole();

    expect($bet->stake_cents)->toBe(2500)
        ->and($bet->status)->toBe('pending')
        ->and($bet->source)->toBe('manual');

    // The message says it, because a product with a button marked "Bet"
    // owes the customer that sentence.
    expect(session('status'))->toContain('holds no money');
});

it('writes the event before the row it derives', function () {
    $user = betUser();

    app(BetLedger::class)->manual($user, [
        'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Bills',
        'price_american' => -110, 'stake_cents' => 2500,
    ]);

    $event = UserBetEvent::sole();

    expect($event->type)->toBe(UserBetEvent::PLACED)
        ->and($event->actor_id)->toBe($user->id)
        ->and($event->payload['stake_cents'])->toBe(2500)
        // The projection points at the event it came from, so a stale
        // row is visible rather than inferred.
        ->and(UserBet::sole()->last_event_id)->toBe($event->id);
});

it('never rewrites a bet event', function () {
    $user = betUser();

    app(BetLedger::class)->manual($user, [
        'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Bills',
        'price_american' => -110, 'stake_cents' => 2500,
    ]);

    // A track record is a claim about the past, and a claim about the
    // past that lives in a mutable row is not evidence of anything.
    expect(fn () => UserBetEvent::sole()->update(['type' => 'bet.settled']))
        ->toThrow(RuntimeException::class, 'append-only');
});

it('takes the stake out of the bankroll when the bet is logged, not when it settles', function () {
    $user = betUser();
    app(Bankroll::class)->settings($user)->update(['starting_cents' => 100_000]);

    app(BetLedger::class)->manual($user, [
        'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Bills',
        'price_american' => -110, 'stake_cents' => 2500,
    ]);

    // Money on a running bet is money that is gone until it is not.
    expect(app(Bankroll::class)->balanceCents($user))->toBe(97_500)
        ->and(BankrollEntry::sole()->amount_cents)->toBe(-2500);
});

it('refuses to log a bet against a market that is not proven', function () {
    $user = betUser();
    $entry = provenEntry(['market_verdict' => 'unproven', 'recommendation' => 'pass']);

    $this->actingAs($user)
        ->post(route('bets.store'), ['board_entry_id' => $entry->id, 'stake' => 25])
        ->assertSessionHasErrors('stake');

    // The gate, again, on the way in: a board entry id is a number
    // anybody can type into a form.
    expect(UserBet::count())->toBe(0);
});

it('logs a board pick in one tap, sized from the bankroll', function () {
    $user = betUser();
    app(Bankroll::class)->settings($user)->update(['starting_cents' => 100_000]);

    $entry = provenEntry();

    $this->actingAs($user)
        ->post(route('bets.store'), ['board_entry_id' => $entry->id])
        ->assertRedirect();

    $bet = UserBet::sole();

    expect($bet->source)->toBe('board')
        ->and($bet->board_entry_id)->toBe($entry->id)
        ->and($bet->stake_cents)->toBeGreaterThan(0);
});

it('asks for a stake rather than inventing one when there is no bankroll', function () {
    $entry = provenEntry();

    $this->actingAs(betUser())
        ->post(route('bets.store'), ['board_entry_id' => $entry->id])
        ->assertSessionHasErrors('stake');

    // Guessing $10 would put a figure in somebody's record that they
    // never chose.
    expect(UserBet::count())->toBe(0);
});

it('caps the free tracker at twenty-five and keeps what is there', function () {
    $user = betUser(null);

    foreach (range(1, 25) as $i) {
        app(BetLedger::class)->manual($user, [
            'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Bet '.$i,
            'price_american' => -110, 'stake_cents' => 100,
        ]);
    }

    $this->actingAs($user)
        ->post(route('bets.store'), [
            'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Twenty six',
            'price_american' => -110, 'stake' => 1,
        ])
        ->assertSessionHasErrors('stake');

    // Refused with a reason, and the twenty-five stay.
    expect(UserBet::count())->toBe(25);
    expect(session('errors')->first('stake'))->toContain('Nothing has been deleted');
});

/* ----------------------------------------------------------------------
 | Settlement
 ---------------------------------------------------------------------- */

it('pays a winner at the price that was taken', function () {
    $user = betUser();

    $bet = app(BetLedger::class)->manual($user, [
        'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Bills',
        'price_american' => 150, 'stake_cents' => 2000,
    ]);

    app(BetLedger::class)->settle($bet, 'won');

    // +150 on $20 returns $50: the stake and $30 of winnings.
    expect($bet->refresh()->return_cents)->toBe(5000)
        ->and($bet->profitCents())->toBe(3000)
        ->and(app(Bankroll::class)->balanceCents($user))->toBe(3000);
});

it('pays a favourite correctly too', function () {
    $user = betUser();

    $bet = app(BetLedger::class)->manual($user, [
        'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Chiefs',
        'price_american' => -200, 'stake_cents' => 2000,
    ]);

    app(BetLedger::class)->settle($bet, 'won');

    // American odds are two different formulas either side of zero, and
    // the negative one is where it gets written wrong.
    expect($bet->refresh()->return_cents)->toBe(3000)
        ->and($bet->profitCents())->toBe(1000);
});

it('returns the stake on a push and on a void, and tells them apart', function (string $outcome) {
    $user = betUser();

    $bet = app(BetLedger::class)->manual($user, [
        'sport' => 'nfl', 'market' => 'spreads', 'selection' => 'Bills -3',
        'side' => 'home', 'line' => -3, 'price_american' => -110, 'stake_cents' => 2000,
    ]);

    app(BetLedger::class)->settle($bet, $outcome);

    expect($bet->refresh()->return_cents)->toBe(2000)
        ->and($bet->profitCents())->toBe(0)
        ->and($bet->status)->toBe($outcome);
})->with(['push', 'void']);

it('keeps a void out of the record and a push in it', function () {
    $user = betUser();

    foreach (['push', 'void'] as $outcome) {
        $bet = app(BetLedger::class)->manual($user, [
            'sport' => 'nfl', 'market' => 'h2h', 'selection' => $outcome,
            'price_american' => -110, 'stake_cents' => 1000,
        ]);

        app(BetLedger::class)->settle($bet, $outcome);
    }

    // A push was a prediction that got tested. A void was a game that
    // never ran, and counting it would dilute the sample with nothing.
    expect(UserBet::query()->settled()->count())->toBe(1)
        ->and(UserBet::query()->settled()->sole()->status)->toBe('push');
});

it('does not settle the same bet twice', function () {
    $user = betUser();

    $bet = app(BetLedger::class)->manual($user, [
        'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Bills',
        'price_american' => 100, 'stake_cents' => 1000,
    ]);

    app(BetLedger::class)->settle($bet, 'won');
    app(BetLedger::class)->settle($bet->refresh(), 'won');
    app(BetLedger::class)->settle($bet->refresh(), 'lost');

    // The settler runs on a schedule; re-settling would double the
    // return in the ledger and quietly invent money.
    expect($bet->refresh()->return_cents)->toBe(2000)
        ->and(BankrollEntry::where('kind', 'return')->count())->toBe(1);
});

it('records that a settlement came from a score rather than from a person', function () {
    $user = betUser();

    $bet = app(BetLedger::class)->manual($user, [
        'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Bills',
        'price_american' => -110, 'stake_cents' => 1000,
    ]);

    app(BetLedger::class)->settle($bet, 'lost');

    $settled = UserBetEvent::where('type', UserBetEvent::SETTLED)->sole();

    // "The engine graded this from the final score" and "somebody marked
    // this won" are different claims about a track record.
    expect($settled->byHand())->toBeFalse()
        ->and($settled->reason)->toContain('final score');
});

/* ----------------------------------------------------------------------
 | The export
 ---------------------------------------------------------------------- */

it('exports the customers own bets and nobody elses', function () {
    $mine = betUser();
    $theirs = betUser();

    app(BetLedger::class)->manual($mine, [
        'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'My own bet',
        'price_american' => -110, 'stake_cents' => 1000,
    ]);

    app(BetLedger::class)->manual($theirs, [
        'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Somebody elses bet',
        'price_american' => -110, 'stake_cents' => 1000,
    ]);

    $csv = $this->actingAs($mine)->get(route('bets.export'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
        ->getContent();

    // §8: "CSV export of own bets only". Scoped in the query rather than
    // filtered afterwards, so there is no variant of this that leaks a
    // row because a parameter was forgotten.
    expect($csv)->toContain('My own bet')
        ->and($csv)->not->toContain('Somebody elses bet');
});

it('exports nothing but the customers own bets, never an odds slate', function () {
    $user = betUser();

    // A board full of prices exists, and none of it belongs in here.
    provenEntry(['id' => 1]);
    provenEntry(['id' => 2, 'selection' => 'Some other game']);

    app(BetLedger::class)->manual($user, [
        'sport' => 'nfl', 'market' => 'h2h', 'selection' => 'Only this',
        'price_american' => -110, 'stake_cents' => 1000,
    ]);

    $csv = $this->actingAs($user)->get(route('bets.export'))->assertOk()->getContent();

    // §9 forbids an odds export, and a bet export is exactly where one
    // would get smuggled in. One header row and one bet.
    expect(substr_count(trim($csv), "\n"))->toBe(1)
        ->and($csv)->not->toContain('Some other game');
});

it('exports a header row even with nothing logged', function () {
    $csv = $this->actingAs(betUser())->get(route('bets.export'))->assertOk()->getContent();

    // An empty file would look like a broken download.
    expect($csv)->toContain('placed_at')->toContain('selection');
});
