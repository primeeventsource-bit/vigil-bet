<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\Side;
use App\Jobs\Odds\MarkClosingLines;
use App\Modeling\Odds\OddsConverter;
use App\Models\Book;
use App\Models\Event;
use App\Models\EventLineMarker;
use App\Models\OddsSnapshot;
use App\Models\Team;

function closingQuote(Event $event, Book $book, int $price, $pulledAt): void
{
    OddsSnapshot::create([
        'event_id' => $event->id,
        'book_id' => $book->id,
        'market' => Market::Moneyline->value,
        'side' => Side::Home->value,
        'price_american' => $price,
        'price_decimal' => OddsConverter::americanToDecimal($price),
        'pulled_at' => $pulledAt,
        'created_at' => now(),
    ]);
}

beforeEach(function () {
    $home = Team::create(['sport' => 'nfl', 'name' => 'Home', 'display_name' => 'Home', 'slug' => 'home']);
    $away = Team::create(['sport' => 'nfl', 'name' => 'Away', 'display_name' => 'Away', 'slug' => 'away']);

    $this->book = Book::create(['key' => 'draftkings', 'title' => 'DraftKings']);

    $this->event = Event::create([
        'sport' => 'nfl',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'starts_at' => now()->subHour(),
        'status' => 'final',
    ]);
});

it('takes the last price before kickoff as the closing line', function () {
    closingQuote($this->event, $this->book, -130, now()->subDays(2));
    closingQuote($this->event, $this->book, -145, now()->subHours(3));
    closingQuote($this->event, $this->book, -155, now()->subHour()->subMinute());
    // Pulled after kickoff: must never be treated as closing.
    closingQuote($this->event, $this->book, -400, now()->subMinutes(30));

    dispatch_sync(new MarkClosingLines);

    $marker = EventLineMarker::first();

    expect($marker->price_open_american)->toBe(-130)
        ->and($marker->price_close_american)->toBe(-155);
});

it('leaves the closing line empty when every closingQuote arrived after kickoff', function () {
    closingQuote($this->event, $this->book, -200, now()->subMinutes(10));

    dispatch_sync(new MarkClosingLines);

    $marker = EventLineMarker::first();

    expect($marker->price_open_american)->toBe(-200)
        ->and($marker->price_close_american)->toBeNull();
});

it('marks each book and side separately', function () {
    $second = Book::create(['key' => 'fanduel', 'title' => 'FanDuel']);

    closingQuote($this->event, $this->book, -150, now()->subHours(2));
    closingQuote($this->event, $second, -140, now()->subHours(2));

    dispatch_sync(new MarkClosingLines);

    expect(EventLineMarker::count())->toBe(2);
});

it('ignores games that have not started', function () {
    $this->event->forceFill(['starts_at' => now()->addDay()])->save();
    closingQuote($this->event, $this->book, -150, now());

    dispatch_sync(new MarkClosingLines);

    expect(EventLineMarker::count())->toBe(0);
});

it('catches up on an old game the scheduler missed entirely', function () {
    // The failure this guards: the worker is down for days, and those games
    // fall out of any fixed lookback window. Their closing lines would never
    // be computed, and CLV for every bet on them would be lost for good.
    $stale = Event::create([
        'sport' => 'nfl',
        'home_team_id' => $this->event->home_team_id,
        'away_team_id' => $this->event->away_team_id,
        'starts_at' => now()->subDays(12),
        'status' => 'final',
    ]);

    closingQuote($stale, $this->book, -150, now()->subDays(12)->addMinutes(-30)->diffInMinutes(now()));

    dispatch_sync(new MarkClosingLines);

    expect(EventLineMarker::where('event_id', $stale->id)->exists())->toBeTrue();
});

it('leaves an already marked old game alone', function () {
    dispatch_sync(new MarkClosingLines);
    $first = EventLineMarker::where('event_id', $this->event->id)->count();

    dispatch_sync(new MarkClosingLines);

    expect(EventLineMarker::where('event_id', $this->event->id)->count())->toBe($first);
});

it('skips games that never had a single closingQuote', function () {
    closingQuote($this->event, $this->book, -140, 120);

    Event::create([
        'sport' => 'nfl',
        'home_team_id' => $this->event->away_team_id,
        'away_team_id' => $this->event->home_team_id,
        'starts_at' => now()->subDays(3),
        'status' => 'final',
    ]);

    dispatch_sync(new MarkClosingLines);

    // Only the event that actually has snapshots gets a marker.
    expect(EventLineMarker::distinct('event_id')->count('event_id'))->toBe(1);
});
