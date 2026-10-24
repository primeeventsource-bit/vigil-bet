<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\Market;
use App\Enums\Side;
use App\Enums\Sport;
use App\Exceptions\AppendOnlyViolation;
use App\Models\Book;
use App\Models\Event;
use App\Models\OddsSnapshot;
use App\Models\Team;

function makeSnapshot(): OddsSnapshot
{
    $home = Team::create(['sport' => Sport::Nfl->value, 'name' => 'Buffalo Bills', 'display_name' => 'Buffalo Bills', 'slug' => 'buffalo-bills', 'abbreviation' => 'BUF']);
    $away = Team::create(['sport' => Sport::Nfl->value, 'name' => 'Miami Dolphins', 'display_name' => 'Miami Dolphins', 'slug' => 'miami-dolphins', 'abbreviation' => 'MIA']);
    $book = Book::create(['key' => 'draftkings', 'title' => 'DraftKings']);

    $event = Event::create([
        'sport' => Sport::Nfl->value,
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'starts_at' => now()->addDay(),
        'status' => EventStatus::Scheduled->value,
    ]);

    return OddsSnapshot::create([
        'event_id' => $event->id,
        'book_id' => $book->id,
        'market' => Market::Moneyline->value,
        'side' => Side::Home->value,
        'price_american' => -140,
        'price_decimal' => 1.7143,
        'pulled_at' => now(),
        'created_at' => now(),
    ]);
}

it('refuses to update a stored quote', function () {
    $snapshot = makeSnapshot();

    expect(fn () => $snapshot->update(['price_american' => -120]))
        ->toThrow(AppendOnlyViolation::class);

    expect(OddsSnapshot::find($snapshot->id)->price_american)->toBe(-140);
});

it('refuses to delete a stored quote', function () {
    $snapshot = makeSnapshot();

    expect(fn () => $snapshot->delete())->toThrow(AppendOnlyViolation::class);

    expect(OddsSnapshot::count())->toBe(1);
});

it('records a changed price as a new row rather than an edit', function () {
    $first = makeSnapshot();

    $second = OddsSnapshot::create([
        'event_id' => $first->event_id,
        'book_id' => $first->book_id,
        'market' => Market::Moneyline->value,
        'side' => Side::Home->value,
        'price_american' => -155,
        'price_decimal' => 1.6452,
        'pulled_at' => now()->addMinutes(10),
        'created_at' => now(),
    ]);

    expect(OddsSnapshot::count())->toBe(2)
        ->and($second->price_american)->toBe(-155)
        ->and($first->fresh()->price_american)->toBe(-140);
});
