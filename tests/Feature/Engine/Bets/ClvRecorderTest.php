<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\Side;
use App\Jobs\Bets\RecordBetClv;
use App\Models\BankrollLedgerEntry;
use App\Models\Book;
use App\Models\Event;
use App\Models\EventLineMarker;
use App\Models\Team;
use App\Services\Engine\Bets\BetLedger;
use App\Services\Engine\Bets\ClvRecorder;

function closed(Event $event, Book $book, Market $market, Side $side, int $price, ?float $line = null): void
{
    EventLineMarker::create([
        'event_id' => $event->id,
        'book_id' => $book->id,
        'market' => $market->value,
        'side' => $side->value,
        'line_close' => $line,
        'price_close_american' => $price,
        'closed_at' => $event->starts_at->copy()->subMinutes(2),
        'computed_at' => now(),
    ]);
}

beforeEach(function () {
    $this->recorder = app(ClvRecorder::class);
    $this->ledger = app(BetLedger::class);

    $home = Team::create(['sport' => 'nfl', 'name' => 'Home', 'display_name' => 'Home', 'slug' => 'home']);
    $away = Team::create(['sport' => 'nfl', 'name' => 'Away', 'display_name' => 'Away', 'slug' => 'away']);

    $this->book = Book::create(['key' => 'hardrockbet', 'title' => 'Hard Rock Bet']);

    $this->event = Event::create([
        'sport' => 'nfl',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'starts_at' => now()->subHours(3),
        'status' => 'final',
        'final_home_score' => 27,
        'final_away_score' => 20,
    ]);

    BankrollLedgerEntry::create([
        'entry_type' => 'deposit', 'amount_cents' => 500000,
        'balance_after_cents' => 500000, 'occurred_at' => now(), 'created_at' => now(),
    ]);
});

it('values a price against the fair close', function () {
    // Took +100 on a side that closed -120 against +100. Stripping the hold
    // out of that close makes the side a 52.17 percent shot, and the price
    // taken paid as though it were 50.
    $bet = $this->ledger->place($this->event, Market::Moneyline, Side::Home, 100, 10000, bookId: $this->book->id);

    closed($this->event, $this->book, Market::Moneyline, Side::Home, -120);
    closed($this->event, $this->book, Market::Moneyline, Side::Away, 100);

    expect($this->recorder->record($bet))->toBeTrue();

    $bet = $bet->fresh();

    expect($bet->closing_price_american)->toBe(-120)
        ->and($bet->clv_prob_delta)->toEqualWithDelta(0.0217, 0.0005)
        // $100 at even money on a 52.17 percent shot is worth $4.35.
        ->and($bet->clv_cents)->toBe(435);
});

it('reports negative value when the price taken was the worse one', function () {
    $bet = $this->ledger->place($this->event, Market::Moneyline, Side::Home, -120, 10000, bookId: $this->book->id);

    closed($this->event, $this->book, Market::Moneyline, Side::Home, 100);
    closed($this->event, $this->book, Market::Moneyline, Side::Away, -120);

    $this->recorder->record($bet);

    expect($bet->fresh()->clv_prob_delta)->toBeLessThan(0.0)
        ->and($bet->fresh()->clv_cents)->toBeLessThan(0);
});

it('measures against the close at the book the bet was struck at', function () {
    // A better number at a book with no account is not what this bet got.
    $other = Book::create(['key' => 'draftkings', 'title' => 'DraftKings']);

    $bet = $this->ledger->place($this->event, Market::Moneyline, Side::Home, 100, 10000, bookId: $this->book->id);

    closed($this->event, $this->book, Market::Moneyline, Side::Home, -120);
    closed($this->event, $this->book, Market::Moneyline, Side::Away, 100);
    closed($this->event, $other, Market::Moneyline, Side::Home, -200);
    closed($this->event, $other, Market::Moneyline, Side::Away, 170);

    $this->recorder->record($bet);

    expect($bet->fresh()->closing_price_american)->toBe(-120);
});

it('strips the vig rather than flattering the bet with the raw close', function () {
    // Matching the close exactly is worth nothing. Against the raw closing
    // price it would look positive, because that price holds the margin.
    $bet = $this->ledger->place($this->event, Market::Moneyline, Side::Home, -110, 10000, bookId: $this->book->id);

    closed($this->event, $this->book, Market::Moneyline, Side::Home, -110);
    closed($this->event, $this->book, Market::Moneyline, Side::Away, -110);

    $this->recorder->record($bet);

    expect($bet->fresh()->clv_prob_delta)->toEqualWithDelta(-0.0238, 0.0005)
        ->and($bet->fresh()->clv_cents)->toBeLessThan(0);
});

it('values a spread that closed on the same number', function () {
    // Took +100 on a spread that closed -130 against +110, with the three
    // still the three. The fair close makes it 54.3 percent; the price paid
    // as though it were 50.
    $bet = $this->ledger->place($this->event, Market::Spread, Side::Home, 100, 10000, line: -3.0, bookId: $this->book->id);

    closed($this->event, $this->book, Market::Spread, Side::Home, -130, -3.0);
    closed($this->event, $this->book, Market::Spread, Side::Away, 110, 3.0);

    $this->recorder->record($bet);

    expect($bet->fresh()->closing_line)->toBe(-3.0)
        ->and($bet->fresh()->clv_prob_delta)->toEqualWithDelta(0.0427, 0.0005)
        ->and($bet->fresh()->clvLineMoved())->toBeFalse();
});

it('does not call a bet good just for beating the raw closing price', function () {
    // -105 into a -115 close looks like value and is not. The closing market
    // of -115 against -105 holds nearly five percent, which puts the fair
    // price at 51.1 percent; -105 pays as though it were 51.2. Beating the
    // posted close is the easiest thing in betting and means almost nothing.
    $bet = $this->ledger->place($this->event, Market::Spread, Side::Home, -105, 10000, line: -3.0, bookId: $this->book->id);

    closed($this->event, $this->book, Market::Spread, Side::Home, -115, -3.0);
    closed($this->event, $this->book, Market::Spread, Side::Away, -105, 3.0);

    $this->recorder->record($bet);

    expect($bet->fresh()->clv_prob_delta)->toBeLessThan(0.0)
        ->and($bet->fresh()->clv_prob_delta)->toEqualWithDelta(-0.0014, 0.0005);
});

it('refuses to state a value when the line moved under it', function () {
    // -3 closing at -3.5 is a real gain that the price alone does not show,
    // and converting half a point into probability takes a scoring model.
    // The closing facts are recorded; the value is not invented.
    $bet = $this->ledger->place($this->event, Market::Spread, Side::Home, -110, 10000, line: -3.0, bookId: $this->book->id);

    closed($this->event, $this->book, Market::Spread, Side::Home, -110, -3.5);
    closed($this->event, $this->book, Market::Spread, Side::Away, -110, 3.5);

    expect($this->recorder->record($bet))->toBeTrue();

    $bet = $bet->fresh();

    expect($bet->closing_line)->toBe(-3.5)
        ->and($bet->closing_price_american)->toBe(-110)
        ->and($bet->clv_prob_delta)->toBeNull()
        ->and($bet->clv_cents)->toBeNull()
        ->and($bet->clvLineMoved())->toBeTrue()
        ->and($bet->clvUnavailableReason())->toContain('line moved');
});

it('records the close but no value when only one side closed', function () {
    $bet = $this->ledger->place($this->event, Market::Moneyline, Side::Home, 100, 10000, bookId: $this->book->id);

    closed($this->event, $this->book, Market::Moneyline, Side::Home, -120);

    expect($this->recorder->record($bet))->toBeTrue();

    $bet = $bet->fresh();

    expect($bet->closing_price_american)->toBe(-120)
        ->and($bet->clv_prob_delta)->toBeNull()
        ->and($bet->clvUnavailableReason())->toContain('one side');
});

it('writes nothing when the market never closed', function () {
    $bet = $this->ledger->place($this->event, Market::Moneyline, Side::Home, 100, 10000, bookId: $this->book->id);

    expect($this->recorder->record($bet))->toBeFalse()
        ->and($bet->fresh()->closing_price_american)->toBeNull()
        ->and($bet->fresh()->clvUnavailableReason())->toContain('has not closed');
});

it('leaves the stake, status and payout untouched', function () {
    // CLV is market data about the price. Money and status belong to the
    // append-only bet ledger and are projected from it.
    $bet = $this->ledger->place($this->event, Market::Moneyline, Side::Home, 100, 10000, bookId: $this->book->id);

    closed($this->event, $this->book, Market::Moneyline, Side::Home, -120);
    closed($this->event, $this->book, Market::Moneyline, Side::Away, 100);

    $this->recorder->record($bet);

    expect($bet->fresh()->stake_cents)->toBe(10000)
        ->and($bet->fresh()->status->value)->toBe('pending')
        ->and($bet->fresh()->payout_cents)->toBe(0)
        ->and($bet->fresh()->events)->toHaveCount(1);
});

it('stamps every placed bet the job finds', function () {
    $bet = $this->ledger->place($this->event, Market::Moneyline, Side::Home, 100, 10000, bookId: $this->book->id);

    closed($this->event, $this->book, Market::Moneyline, Side::Home, -120);
    closed($this->event, $this->book, Market::Moneyline, Side::Away, 100);

    dispatch_sync(new RecordBetClv);

    expect($bet->fresh()->clv_cents)->toBe(435);
});

it('fills in the value once the other side of the market closes', function () {
    // MarkClosingLines keeps revisiting recent games, so a close that was
    // one-sided on the first pass often has both sides an hour later. A bet
    // already carrying a closing price has to be picked up again or its CLV
    // is lost for good.
    $bet = $this->ledger->place($this->event, Market::Moneyline, Side::Home, 100, 10000, bookId: $this->book->id);

    closed($this->event, $this->book, Market::Moneyline, Side::Home, -120);

    dispatch_sync(new RecordBetClv);

    expect($bet->fresh()->closing_price_american)->toBe(-120)
        ->and($bet->fresh()->clv_prob_delta)->toBeNull();

    closed($this->event, $this->book, Market::Moneyline, Side::Away, 100);

    dispatch_sync(new RecordBetClv);

    expect($bet->fresh()->clv_cents)->toBe(435);
});

it('stops retrying a close that never arrived', function () {
    // An old bet whose market closed one-sided is never going to improve,
    // and rescanning it forever would grow with the bet history.
    $this->event->forceFill(['starts_at' => now()->subDays(10)])->save();

    $bet = $this->ledger->place($this->event, Market::Moneyline, Side::Home, 100, 10000, bookId: $this->book->id);

    closed($this->event, $this->book, Market::Moneyline, Side::Home, -120);

    dispatch_sync(new RecordBetClv);

    closed($this->event, $this->book, Market::Moneyline, Side::Away, 100);

    dispatch_sync(new RecordBetClv);

    expect($bet->fresh()->clv_prob_delta)->toBeNull();
});

it('values a paper bet too', function () {
    // Paper bets exist to build a record, and the record is CLV.
    $bet = $this->ledger->place($this->event, Market::Moneyline, Side::Home, 100, 10000, bookId: $this->book->id, isPaper: true);

    closed($this->event, $this->book, Market::Moneyline, Side::Home, -120);
    closed($this->event, $this->book, Market::Moneyline, Side::Away, 100);

    dispatch_sync(new RecordBetClv);

    expect($bet->fresh()->clv_cents)->toBe(435);
});
