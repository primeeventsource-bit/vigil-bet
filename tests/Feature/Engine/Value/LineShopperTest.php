<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\Side;
use App\Modeling\Odds\LineShopper;
use App\Modeling\Odds\OddsConverter;
use App\Models\Book;
use App\Models\Event;
use App\Models\OddsSnapshot;
use App\Models\Team;

function priceAt(Event $event, Book $book, int $american, int $minutesAgo = 1): void
{
    OddsSnapshot::create([
        'event_id' => $event->id,
        'book_id' => $book->id,
        'market' => Market::Moneyline->value,
        'side' => Side::Home->value,
        'price_american' => $american,
        'price_decimal' => OddsConverter::americanToDecimal($american),
        'pulled_at' => now()->subMinutes($minutesAgo),
        'created_at' => now(),
    ]);
}

beforeEach(function () {
    $home = Team::create(['sport' => 'nhl', 'name' => 'Tampa Bay Lightning', 'display_name' => 'Tampa Bay Lightning', 'slug' => 'tampa-bay-lightning']);
    $away = Team::create(['sport' => 'nhl', 'name' => 'Away', 'display_name' => 'Away', 'slug' => 'away']);

    $this->event = Event::create([
        'sport' => 'nhl',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'starts_at' => now()->addHours(5),
        'status' => 'scheduled',
    ]);

    $this->hardRock = Book::create(['key' => 'hardrockbet', 'title' => 'Hard Rock Bet', 'is_tracked' => true, 'is_bettable' => true, 'is_primary' => true]);
    $this->draftKings = Book::create(['key' => 'draftkings', 'title' => 'DraftKings', 'is_tracked' => true, 'is_bettable' => true]);
    $this->betmgm = Book::create(['key' => 'betmgm', 'title' => 'BetMGM', 'is_tracked' => true, 'is_bettable' => false]);

    $this->shopper = app(LineShopper::class);
});

it('prices everything at Hard Rock', function () {
    priceAt($this->event, $this->hardRock, -115);
    priceAt($this->event, $this->draftKings, -105);
    priceAt($this->event, $this->betmgm, 100);

    $shopped = $this->shopper->shop($this->event, Market::Moneyline, Side::Home);

    expect($shopped->pricingBookPriceAmerican)->toBe(-115)
        ->and($shopped->pricingBookTitle)->toBe('Hard Rock Bet');
});

it('still records the best number on the board without recommending it', function () {
    priceAt($this->event, $this->hardRock, 105);
    priceAt($this->event, $this->betmgm, 112);

    $shopped = $this->shopper->shop($this->event, Market::Moneyline, Side::Home);

    // The Lightning case: a pick at +112 elsewhere, only +105 here.
    expect($shopped->pricingBookPriceAmerican)->toBe(105)
        ->and($shopped->bestMarketPriceAmerican)->toBe(112)
        ->and($shopped->bestMarketBookTitle)->toBe('BetMGM')
        ->and($shopped->betterElsewhere())->toBeTrue()
        ->and($shopped->costOfPricingBook())->toBeGreaterThan(0.0);
});

it('has no price at all when Hard Rock is not quoting', function () {
    priceAt($this->event, $this->draftKings, -105);
    priceAt($this->event, $this->betmgm, 100);

    $shopped = $this->shopper->shop($this->event, Market::Moneyline, Side::Home);

    expect($shopped->hasPrice())->toBeFalse()
        ->and($shopped->bookCount)->toBe(2);
});

it('reports the break even rate for the Hard Rock price', function () {
    priceAt($this->event, $this->hardRock, -200);

    $shopped = $this->shopper->shop($this->event, Market::Moneyline, Side::Home);

    expect($shopped->breakEvenWinRate())->toEqualWithDelta(0.6667, 0.0001);
});

it('can be switched to price everything at DraftKings', function () {
    priceAt($this->event, $this->hardRock, -115);
    priceAt($this->event, $this->draftKings, -105);

    $shopped = $this->shopper->shop($this->event, Market::Moneyline, Side::Home, pricingBookKey: 'draftkings');

    expect($shopped->pricingBookPriceAmerican)->toBe(-105)
        ->and($shopped->pricingBookTitle)->toBe('DraftKings');
});

it('uses only the newest Hard Rock quote', function () {
    priceAt($this->event, $this->hardRock, -130, minutesAgo: 15);
    priceAt($this->event, $this->hardRock, -108, minutesAgo: 2);

    $shopped = $this->shopper->shop($this->event, Market::Moneyline, Side::Home);

    expect($shopped->pricingBookPriceAmerican)->toBe(-108);
});

it('ignores a price too old to act on', function () {
    priceAt($this->event, $this->hardRock, -105, minutesAgo: 240);

    $shopped = $this->shopper->shop($this->event, Market::Moneyline, Side::Home);

    expect($shopped->hasPrice())->toBeFalse();
});

it('keeps the historical closing line bucket out of live pricing', function () {
    $historical = Book::create(['key' => 'historical_close', 'title' => 'Historical closing line', 'is_tracked' => false]);

    priceAt($this->event, $this->hardRock, -115);
    priceAt($this->event, $historical, 250);

    $shopped = $this->shopper->shop($this->event, Market::Moneyline, Side::Home);

    expect($shopped->bestMarketPriceAmerican)->toBe(-115)
        ->and($shopped->bookCount)->toBe(1);
});

it('shops within a single spread number', function () {
    foreach ([[$this->hardRock, -110, -3.5], [$this->draftKings, -105, -3.0]] as [$book, $price, $line]) {
        OddsSnapshot::create([
            'event_id' => $this->event->id,
            'book_id' => $book->id,
            'market' => Market::Spread->value,
            'side' => Side::Home->value,
            'line' => $line,
            'price_american' => $price,
            'price_decimal' => OddsConverter::americanToDecimal($price),
            'pulled_at' => now()->subMinute(),
            'created_at' => now(),
        ]);
    }

    // A better price on a different number is a different bet.
    $shopped = $this->shopper->shop($this->event, Market::Spread, Side::Home, line: -3.5);

    expect($shopped->pricingBookPriceAmerican)->toBe(-110)
        ->and($shopped->bookCount)->toBe(1);
});
