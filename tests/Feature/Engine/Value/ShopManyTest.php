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
use Illuminate\Support\Facades\DB;

/*
 * Shopping a whole slate in one query.
 *
 * The board asks for forty games across three markets and two sides. One
 * call at a time that is 240 queries against an odds_snapshots table with
 * a couple of hundred thousand rows in it: on production it took sixteen
 * seconds and returned a 504 at the gateway. These pin both halves of the
 * fix - the batched answer is identical to the single-shot answer, and it
 * costs one query rather than one per side.
 */

function slateBooks(): array
{
    return [
        Book::firstOrCreate(['key' => 'hardrockbet'], ['title' => 'Hard Rock Bet', 'is_tracked' => true, 'is_bettable' => true]),
        Book::firstOrCreate(['key' => 'draftkings'], ['title' => 'DraftKings', 'is_tracked' => true, 'is_bettable' => true]),
        Book::firstOrCreate(['key' => 'betmgm'], ['title' => 'BetMGM', 'is_tracked' => true, 'is_bettable' => false]),
    ];
}

/** One upcoming game priced across three books, all three markets. */
function slateGame(int $n = 1): Event
{
    $home = Team::firstOrCreate(['sport' => 'nfl', 'slug' => "slate-home-{$n}"], ['name' => "Home {$n}", 'display_name' => "Home {$n}"]);
    $away = Team::firstOrCreate(['sport' => 'nfl', 'slug' => "slate-away-{$n}"], ['name' => "Away {$n}", 'display_name' => "Away {$n}"]);

    $event = Event::create([
        'sport' => 'nfl',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'starts_at' => now()->addHours(4 + $n),
        'status' => 'scheduled',
    ]);

    foreach (slateBooks() as $i => $book) {
        foreach ([
            [Market::Moneyline, Side::Home, null, -150 - $i * 5],
            [Market::Moneyline, Side::Away, null, 130 + $i * 5],
            [Market::Spread, Side::Home, -3.0, -110 - $i * 5],
            [Market::Spread, Side::Away, 3.0, -110 + $i * 5],
            [Market::Total, Side::Over, 44.5, -112 - $i * 2],
            [Market::Total, Side::Under, 44.5, -108 + $i * 2],
        ] as [$market, $side, $line, $price]) {
            OddsSnapshot::create([
                'event_id' => $event->id,
                'book_id' => $book->id,
                'market' => $market->value,
                'side' => $side->value,
                'line' => $line,
                'price_american' => $price,
                'price_decimal' => OddsConverter::americanToDecimal($price),
                'pulled_at' => now()->subMinutes(10),
                'created_at' => now(),
            ]);
        }
    }

    return $event;
}

it('agrees exactly with shopping one side at a time', function () {
    $event = slateGame();
    $shopper = app(LineShopper::class);
    $markets = [Market::Moneyline, Market::Spread, Market::Total];

    $batch = $shopper->shopMany([$event], $markets, maxAgeMinutes: 60 * 24 * 7);

    foreach ($markets as $market) {
        foreach ($market->sides() as $side) {
            $one = $shopper->shop($event, $market, $side, maxAgeMinutes: 60 * 24 * 7);
            $many = $batch[$event->id.'|'.$market->value.'|'.$side->value] ?? null;

            expect($many)->not->toBeNull();
            expect($many->pricingBookPriceAmerican)->toBe($one->pricingBookPriceAmerican)
                ->and($many->pricingBookTitle)->toBe($one->pricingBookTitle)
                ->and($many->bestMarketPriceAmerican)->toBe($one->bestMarketPriceAmerican)
                ->and($many->bestMarketBookTitle)->toBe($one->bestMarketBookTitle)
                ->and($many->bookCount)->toBe($one->bookCount)
                ->and((float) $many->line)->toBe((float) $one->line)
                ->and($many->hasPrice())->toBe($one->hasPrice());
        }
    }
});

it('costs one snapshot query however many games are asked for', function () {
    $events = collect(range(1, 6))->map(fn (int $i) => slateGame($i));

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(LineShopper::class)->shopMany(
        $events,
        [Market::Moneyline, Market::Spread, Market::Total],
        maxAgeMinutes: 60 * 24 * 7,
    );

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // One for the snapshots and one for the books. Six games across three
    // markets and two sides would otherwise be thirty-six round trips.
    expect(count($queries))->toBeLessThanOrEqual(2);
});

it('takes the newest quote per book', function () {
    $event = slateGame();
    $book = Book::where('key', 'hardrockbet')->sole();

    OddsSnapshot::create([
        'event_id' => $event->id,
        'book_id' => $book->id,
        'market' => Market::Moneyline->value,
        'side' => Side::Home->value,
        'price_american' => -175,
        'price_decimal' => OddsConverter::americanToDecimal(-175),
        'pulled_at' => now()->subMinute(),
        'created_at' => now(),
    ]);

    $batch = app(LineShopper::class)->shopMany([$event], [Market::Moneyline], maxAgeMinutes: 60 * 24 * 7);

    expect($batch[$event->id.'|h2h|home']->pricingBookPriceAmerican)->toBe(-175);
});

it('ignores the closing-line bucket, which is not a price on offer', function () {
    $event = slateGame();

    $closing = Book::firstOrCreate(
        ['key' => 'closing'],
        ['title' => 'Closing', 'is_tracked' => false, 'is_bettable' => false],
    );

    OddsSnapshot::create([
        'event_id' => $event->id,
        'book_id' => $closing->id,
        'market' => Market::Moneyline->value,
        'side' => Side::Home->value,
        'price_american' => 500,
        'price_decimal' => OddsConverter::americanToDecimal(500),
        'pulled_at' => now()->subMinute(),
        'created_at' => now(),
    ]);

    $line = app(LineShopper::class)
        ->shopMany([$event], [Market::Moneyline], maxAgeMinutes: 60 * 24 * 7)[$event->id.'|h2h|home'];

    expect($line->bestMarketPriceAmerican)->not->toBe(500)
        ->and(array_key_exists('closing', $line->quotes))->toBeFalse();
});

it('refuses a quote older than the window, as the single-shot path does', function () {
    $event = slateGame();

    // Every quote on this game is ten minutes old.
    expect(app(LineShopper::class)->shopMany([$event], [Market::Moneyline], maxAgeMinutes: 5))->toBe([]);
});

it('returns nothing rather than erroring when asked for nothing', function () {
    expect(app(LineShopper::class)->shopMany([], [Market::Moneyline]))->toBe([])
        ->and(app(LineShopper::class)->shopMany([slateGame()], []))->toBe([]);
});

it('keys every side of every market it found', function () {
    $event = slateGame();

    $batch = app(LineShopper::class)->shopMany(
        [$event],
        [Market::Moneyline, Market::Spread, Market::Total],
        maxAgeMinutes: 60 * 24 * 7,
    );

    foreach (['h2h|home', 'h2h|away', 'spreads|home', 'spreads|away', 'totals|over', 'totals|under'] as $suffix) {
        expect($batch)->toHaveKey($event->id.'|'.$suffix);
    }
});

it('picks the newest quote by time, not by whichever row was inserted last', function () {
    // The line backfill writes historical closing prices long after the
    // live pulls they predate, so the highest id in a group can hold the
    // oldest quote. Grouping on max(id) would pick that stale row and
    // report a months-old price as the current one.
    $event = slateGame();
    $book = Book::where('key', 'hardrockbet')->sole();

    $live = OddsSnapshot::create([
        'event_id' => $event->id,
        'book_id' => $book->id,
        'market' => Market::Moneyline->value,
        'side' => Side::Home->value,
        'price_american' => -165,
        'price_decimal' => OddsConverter::americanToDecimal(-165),
        'pulled_at' => now()->subMinute(),
        'created_at' => now(),
    ]);

    // Written afterwards, so a higher id, but describing an older moment.
    $backfilled = OddsSnapshot::create([
        'event_id' => $event->id,
        'book_id' => $book->id,
        'market' => Market::Moneyline->value,
        'side' => Side::Home->value,
        'price_american' => 999,
        'price_decimal' => OddsConverter::americanToDecimal(999),
        'pulled_at' => now()->subDays(3),
        'created_at' => now(),
    ]);

    expect($backfilled->id)->toBeGreaterThan($live->id);

    $line = app(LineShopper::class)
        ->shopMany([$event], [Market::Moneyline], maxAgeMinutes: 60 * 24 * 7)[$event->id.'|h2h|home'];

    expect($line->pricingBookPriceAmerican)->toBe(-165);
});
