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
use Illuminate\Support\Facades\DB;

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

/*
|--------------------------------------------------------------------------
| The tail of this table
|--------------------------------------------------------------------------
|
| Snapshots average about sixteen hundred per event in production and the
| worst single event holds forty-seven thousand. The job used to load every
| core-market quote for an event into Eloquent to pick the first and last of
| each group, so its cost was set by the largest event in the batch - and one
| pathological game made a run take over four hours, be closed by the reaper
| at thirty minutes, retried, and die the same way.
|
| These two tests are the guard. The first says the answer is still right
| when a group has hundreds of quotes; the second says the work does not
| grow with them, which is the part that actually regressed and the part a
| correctness test cannot see.
|
*/

it('picks the right ends out of hundreds of quotes on one group', function () {
    $kickoff = $this->event->starts_at;

    // Two hundred quotes before kickoff, drifting one point at a time, and
    // fifty after it. Only the first and the two-hundredth may be chosen.
    foreach (range(0, 199) as $i) {
        closingQuote($this->event, $this->book, -100 - $i, $kickoff->copy()->subMinutes(400 - $i));
    }

    foreach (range(0, 49) as $i) {
        closingQuote($this->event, $this->book, -900 - $i, $kickoff->copy()->addMinutes($i + 1));
    }

    dispatch_sync(new MarkClosingLines);

    $marker = EventLineMarker::sole();

    expect($marker->price_open_american)->toBe(-100)
        ->and($marker->price_close_american)->toBe(-299)
        // One row per book, market and side - not one per quote.
        ->and(EventLineMarker::count())->toBe(1);
});

it('does not do more work because an event has more quotes', function () {
    $kickoff = $this->event->starts_at;

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        dispatch_sync(new MarkClosingLines);

        $queries = count(DB::getRawQueryLog());

        DB::disableQueryLog();

        return $queries;
    };

    foreach (range(0, 9) as $i) {
        closingQuote($this->event, $this->book, -110 - $i, $kickoff->copy()->subMinutes(100 - $i));
    }

    $withTen = $count();

    foreach (range(0, 489) as $i) {
        closingQuote($this->event, $this->book, -200 - $i, $kickoff->copy()->subMinutes(600 + $i));
    }

    $withFiveHundred = $count();

    /*
     * Fifty times the quotes, the same number of statements. Asserted as
     * equality rather than as a ceiling: a ceiling would still pass if the
     * cost crept back to being per-quote and the ceiling were generous,
     * and being per-quote is the whole defect.
     */
    expect($withFiveHundred)->toBe($withTen)
        // And the answer still comes from the right rows: the later batch
        // was stamped earlier, so it holds the new opening quote.
        ->and(EventLineMarker::sole()->price_open_american)->toBe(-689);
});
