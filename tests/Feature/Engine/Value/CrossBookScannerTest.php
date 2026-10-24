<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\Side;
use App\Exceptions\AppendOnlyViolation;
use App\Modeling\Odds\OddsConverter;
use App\Models\ArbitrageOpportunity;
use App\Models\Book;
use App\Models\Event;
use App\Models\MiddleOpportunity;
use App\Models\ModelVersion;
use App\Models\OddsSnapshot;
use App\Models\Prediction;
use App\Models\Team;
use App\Services\Value\CrossBookScanner;

/*
 * One game, priced at several books, with the prices bent one at a time.
 * The arbitrage and middle finders never consult a model to decide
 * anything, so these fixtures carry no prediction unless the test is
 * specifically about the model's view of a gap.
 */

function crossBookGame(): Event
{
    $home = Team::create(['sport' => 'nfl', 'name' => 'Bills', 'display_name' => 'Buffalo Bills', 'slug' => 'bills']);
    $away = Team::create(['sport' => 'nfl', 'name' => 'Jets', 'display_name' => 'New York Jets', 'slug' => 'jets']);

    return Event::create([
        'sport' => 'nfl',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'starts_at' => now()->addHours(6),
        'status' => 'scheduled',
    ]);
}

function crossBookPrice(
    Event $event,
    string $bookKey,
    Market $market,
    Side $side,
    int $price,
    ?float $line = null,
    bool $bettable = true,
    int $minutesAgo = 1,
    bool $tracked = true,
): void {
    $book = Book::firstOrCreate(
        ['key' => $bookKey],
        ['title' => mb_strtoupper($bookKey), 'is_tracked' => $tracked, 'is_bettable' => $bettable]
    );

    OddsSnapshot::create([
        'event_id' => $event->id,
        'book_id' => $book->id,
        'market' => $market->value,
        'side' => $side->value,
        'line' => $line,
        'price_american' => $price,
        'price_decimal' => OddsConverter::americanToDecimal($price),
        'pulled_at' => now()->subMinutes($minutesAgo),
        'created_at' => now(),
    ]);
}

function sweep(): void
{
    app(CrossBookScanner::class)->scan();
}

it('finds nothing in a market the books agree on', function () {
    $event = crossBookGame();

    foreach (['hardrockbet', 'draftkings'] as $book) {
        crossBookPrice($event, $book, Market::Moneyline, Side::Home, -110);
        crossBookPrice($event, $book, Market::Moneyline, Side::Away, -110);
    }

    sweep();

    expect(ArbitrageOpportunity::count())->toBe(0);
});

it('finds the arbitrage when two books disagree far enough', function () {
    $event = crossBookGame();

    // Hard Rock is long on the home side, DraftKings short on the away.
    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Home, 120);
    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Away, -140);
    crossBookPrice($event, 'draftkings', Market::Moneyline, Side::Home, -140);
    crossBookPrice($event, 'draftkings', Market::Moneyline, Side::Away, -105);

    sweep();

    $arb = ArbitrageOpportunity::sole();

    expect($arb->profit_pct)->toBeGreaterThan(0.03)
        ->and($arb->implied_sum)->toBeLessThan(1.0)
        ->and($arb->placeable)->toBeTrue()
        ->and($arb->legs)->toHaveCount(2)
        // It takes the best of each side, which is one leg at each book.
        ->and(collect($arb->legs)->pluck('book_key')->sort()->values()->all())
        ->toBe(['draftkings', 'hardrockbet']);
});

it('stakes the arbitrage so that every result pays', function () {
    $event = crossBookGame();

    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Home, 120);
    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Away, -140);
    crossBookPrice($event, 'draftkings', Market::Moneyline, Side::Home, -140);
    crossBookPrice($event, 'draftkings', Market::Moneyline, Side::Away, -105);

    sweep();

    $arb = ArbitrageOpportunity::sole();

    expect(array_sum($arb->stake_split))->toBe($arb->reference_stake_cents)
        ->and($arb->guaranteed_profit_cents)->toBeGreaterThan(0);
});

it('marks an arbitrage unplaceable when one leg is at a book with no account', function () {
    $event = crossBookGame();

    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Home, 120);
    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Away, -140);
    crossBookPrice($event, 'pinnacle', Market::Moneyline, Side::Home, -140, bettable: false);
    crossBookPrice($event, 'pinnacle', Market::Moneyline, Side::Away, -105, bettable: false);

    sweep();

    // Still recorded - the disagreement is real and worth the history -
    // but never dressed up as a bet that can be struck.
    expect(ArbitrageOpportunity::sole()->placeable)->toBeFalse();
});

it('ignores a quote too old to act on', function () {
    $event = crossBookGame();

    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Home, 120, minutesAgo: 1);
    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Away, -140, minutesAgo: 1);
    // An hour old. This is where phantom arbitrages come from.
    crossBookPrice($event, 'draftkings', Market::Moneyline, Side::Home, -140, minutesAgo: 60);
    crossBookPrice($event, 'draftkings', Market::Moneyline, Side::Away, -105, minutesAgo: 60);

    sweep();

    expect(ArbitrageOpportunity::count())->toBe(0);
});

it('records how old the older leg was', function () {
    $event = crossBookGame();

    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Home, 120, minutesAgo: 1);
    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Away, -140, minutesAgo: 1);
    crossBookPrice($event, 'draftkings', Market::Moneyline, Side::Home, -140, minutesAgo: 8);
    crossBookPrice($event, 'draftkings', Market::Moneyline, Side::Away, -105, minutesAgo: 8);

    sweep();

    expect(ArbitrageOpportunity::sole()->oldest_quote_seconds)->toBeGreaterThanOrEqual(470);
});

it('ignores the closing line bucket, which is not a book', function () {
    $event = crossBookGame();

    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Home, -110);
    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Away, -110);
    crossBookPrice($event, 'closing', Market::Moneyline, Side::Home, 120, bettable: false, tracked: false);
    crossBookPrice($event, 'closing', Market::Moneyline, Side::Away, 120, bettable: false, tracked: false);

    sweep();

    expect(ArbitrageOpportunity::count())->toBe(0);
});

it('will not call two different numbers an arbitrage', function () {
    $event = crossBookGame();

    // Over 44 at one book and Under 47.5 at another is a middle, not an
    // arbitrage: the two prices are not on the same proposition.
    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Over, 120, line: 44.0);
    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Under, -140, line: 44.0);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Over, -140, line: 47.5);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Under, -105, line: 47.5);

    sweep();

    expect(ArbitrageOpportunity::count())->toBe(0)
        ->and(MiddleOpportunity::count())->toBe(1);
});

it('finds the gap between two totals and counts the results inside it', function () {
    $event = crossBookGame();

    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Over, -110, line: 44.0);
    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Under, -110, line: 44.0);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Over, -110, line: 47.5);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Under, -110, line: 47.5);

    sweep();

    $middle = MiddleOpportunity::sole();

    expect($middle->middle_low)->toBe(44.0)
        ->and($middle->middle_high)->toBe(47.5)
        ->and($middle->width)->toBe(3.5)
        // 45, 46 and 47 take both sides.
        ->and($middle->outs)->toBe(3);
});

it('finds the gap between two spreads', function () {
    $event = crossBookGame();

    // Home -2.5 at one book, away +3.5 at another: a 3-point home win
    // takes both.
    crossBookPrice($event, 'hardrockbet', Market::Spread, Side::Home, -110, line: -2.5);
    crossBookPrice($event, 'hardrockbet', Market::Spread, Side::Away, -110, line: 2.5);
    crossBookPrice($event, 'draftkings', Market::Spread, Side::Home, -110, line: -3.5);
    crossBookPrice($event, 'draftkings', Market::Spread, Side::Away, -110, line: 3.5);

    sweep();

    $middle = MiddleOpportunity::sole();

    expect($middle->middle_low)->toBe(2.5)
        ->and($middle->middle_high)->toBe(3.5)
        ->and($middle->outs)->toBe(1);
});

it('states the hit rate the middle needs beside what the board implies', function () {
    $event = crossBookGame();

    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Over, -110, line: 44.0);
    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Under, -110, line: 44.0);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Over, -110, line: 47.5);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Under, -110, line: 47.5);

    sweep();

    $middle = MiddleOpportunity::sole();

    // Two legs at -110 need the gap 4.76% of the time.
    expect($middle->break_even_hit_prob)->toEqualWithDelta(0.047619, 0.000001)
        // Both books priced their own market at 50/50 after vig removal,
        // so the board says the gap is worth nothing at all.
        ->and($middle->market_hit_prob)->toEqualWithDelta(0.0, 0.000001)
        ->and($middle->ev_market)->toBeLessThan(0.0)
        ->and($middle->beatsBreakEven())->toBeFalse();
});

it('never prices a middle as worth having on the board alone', function () {
    // The two legs a middle needs are both the favoured side of their own
    // book - that is what makes it a gap - so the gap is already paid for
    // in the two prices, twice, with the margin on top. See the unit test
    // for the general statement; this holds the scanner to it on a board
    // with a real and wide gap in it.
    $event = crossBookGame();

    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Over, -200, line: 44.0);
    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Under, 170, line: 44.0);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Over, 170, line: 47.5);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Under, -200, line: 47.5);

    sweep();

    $middle = MiddleOpportunity::sole();

    expect($middle->outs)->toBe(3)
        ->and($middle->market_hit_prob)->toBeLessThan($middle->break_even_hit_prob)
        ->and($middle->ev_market)->toBeLessThan(0.0)
        ->and($middle->beatsBreakEven())->toBeFalse();
});

it('leaves the model estimate empty when no model has priced the game', function () {
    $event = crossBookGame();

    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Over, -110, line: 44.0);
    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Under, -110, line: 44.0);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Over, -110, line: 47.5);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Under, -110, line: 47.5);

    sweep();

    expect(MiddleOpportunity::sole()->model_hit_prob)->toBeNull()
        ->and(MiddleOpportunity::sole()->ev_model)->toBeNull();
});

it('reads the model estimate of the gap off the projected total when there is one', function () {
    $event = crossBookGame();

    $version = ModelVersion::create([
        'sport' => 'nfl',
        'market' => Market::Total->value,
        'model_type' => 'normal_margin',
        'version' => 1,
        'params' => ['league' => []],
        'trained_at' => now()->subDay(),
        'trained_through_at' => now()->subDay(),
        'training_rows' => 500,
        'holdout_metrics' => ['log_loss' => 0.62],
        'status' => 'active',
    ]);

    // Centred at 45.75, right inside a 44 to 47.5 gap.
    Prediction::create([
        'event_id' => $event->id,
        'model_version_id' => $version->id,
        'market' => Market::Total->value,
        'side' => Side::Over->value,
        'line' => 45.5,
        'prob' => 0.5,
        'fair_price_american' => -100,
        'projected_total' => 45.75,
        'sigma' => 10.0,
        'predicted_at' => now(),
        'event_starts_at' => $event->starts_at,
        'created_at' => now(),
    ]);

    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Over, -110, line: 44.0);
    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Under, -110, line: 44.0);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Over, -110, line: 47.5);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Under, -110, line: 47.5);

    sweep();

    $middle = MiddleOpportunity::sole();

    // A 3.5-point window around the centre of a sigma-10 normal is about
    // 14%, which comfortably clears the 4.76% it has to.
    expect($middle->model_hit_prob)->toBeGreaterThan(0.12)
        ->and($middle->model_hit_prob)->toBeLessThan(0.16)
        ->and($middle->ev_model)->toBeGreaterThan(0.0)
        // The board and the model are allowed to disagree, and here they
        // flatly do. Neither is averaged into the other.
        ->and($middle->market_hit_prob)->toEqualWithDelta(0.0, 0.000001);
});

it('skips games that have already started', function () {
    $event = crossBookGame();
    $event->update(['starts_at' => now()->subHour()]);

    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Home, 120);
    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Away, -140);
    crossBookPrice($event, 'draftkings', Market::Moneyline, Side::Home, -140);
    crossBookPrice($event, 'draftkings', Market::Moneyline, Side::Away, -105);

    sweep();

    expect(ArbitrageOpportunity::count())->toBe(0);
});

it('reports what it found', function () {
    $event = crossBookGame();

    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Over, -110, line: 44.0);
    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Under, -110, line: 44.0);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Over, -110, line: 47.5);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Under, -110, line: 47.5);

    $result = app(CrossBookScanner::class)->scan();

    expect($result->middles)->toBe(1)
        ->and($result->arbitrages)->toBe(0)
        ->and($result->found())->toBe(1);
});

it('never rewrites an opportunity once recorded', function () {
    $event = crossBookGame();

    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Home, 120);
    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Away, -140);
    crossBookPrice($event, 'draftkings', Market::Moneyline, Side::Home, -140);
    crossBookPrice($event, 'draftkings', Market::Moneyline, Side::Away, -105);

    sweep();

    $arb = ArbitrageOpportunity::sole();
    $arb->profit_pct = 0.99;
    $arb->save();
})->throws(AppendOnlyViolation::class);

it('records which side each leg is, so the row can be read later', function () {
    $event = crossBookGame();

    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Home, 120);
    crossBookPrice($event, 'hardrockbet', Market::Moneyline, Side::Away, -140);
    crossBookPrice($event, 'draftkings', Market::Moneyline, Side::Home, -140);
    crossBookPrice($event, 'draftkings', Market::Moneyline, Side::Away, -105);

    sweep();

    expect(collect(ArbitrageOpportunity::sole()->legs)->pluck('side')->sort()->values()->all())
        ->toBe(['away', 'home']);
});

it('records which side each leg of a middle is', function () {
    $event = crossBookGame();

    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Over, -110, line: 44.0);
    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Under, -110, line: 44.0);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Over, -110, line: 47.5);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Under, -110, line: 47.5);

    sweep();

    $middle = MiddleOpportunity::sole();

    expect($middle->leg_a['side'])->toBe('over')
        ->and($middle->leg_b['side'])->toBe('under')
        // And the number each leg was taken at, which is the whole point.
        ->and((float) $middle->leg_a['line'])->toBe(44.0)
        ->and((float) $middle->leg_b['line'])->toBe(47.5);
});

it('finds an arbitrage on a spread, where the two sides are stored at opposite signs', function () {
    // The case that is easy to get wrong and impossible to notice: a home
    // line of -3 and an away line of +3 are one market. Anything that
    // files both sides under a shared line key stops pairing them, and
    // spread arbitrage silently never fires. Totals hide the bug, because
    // both sides there are quoted at the same number.
    $event = crossBookGame();

    crossBookPrice($event, 'hardrockbet', Market::Spread, Side::Home, 120, line: -3.0);
    crossBookPrice($event, 'hardrockbet', Market::Spread, Side::Away, -140, line: 3.0);
    crossBookPrice($event, 'draftkings', Market::Spread, Side::Home, -140, line: -3.0);
    crossBookPrice($event, 'draftkings', Market::Spread, Side::Away, -105, line: 3.0);

    sweep();

    $arb = ArbitrageOpportunity::sole();

    expect($arb->market)->toBe(Market::Spread)
        ->and((float) $arb->line)->toBe(-3.0)
        ->and($arb->profit_pct)->toBeGreaterThan(0.03)
        ->and(collect($arb->legs)->pluck('side')->sort()->values()->all())->toBe(['away', 'home']);
});

it('does not pair a spread against the wrong number', function () {
    // Home -3 at one book and away +4 at another is not one market. It is
    // a middle, and the arbitrage finder must leave it alone.
    $event = crossBookGame();

    crossBookPrice($event, 'hardrockbet', Market::Spread, Side::Home, 120, line: -3.0);
    crossBookPrice($event, 'hardrockbet', Market::Spread, Side::Away, -140, line: 3.0);
    crossBookPrice($event, 'draftkings', Market::Spread, Side::Home, -140, line: -4.0);
    crossBookPrice($event, 'draftkings', Market::Spread, Side::Away, 115, line: 4.0);

    sweep();

    // Neither book is internally an arbitrage, and the cross pairs are at
    // different numbers, so there is nothing to find.
    expect(ArbitrageOpportunity::count())->toBe(0);
});

it('reads a total arbitrage, where both sides share the number', function () {
    $event = crossBookGame();

    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Over, 120, line: 45.5);
    crossBookPrice($event, 'hardrockbet', Market::Total, Side::Under, -140, line: 45.5);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Over, -140, line: 45.5);
    crossBookPrice($event, 'draftkings', Market::Total, Side::Under, -105, line: 45.5);

    sweep();

    expect(ArbitrageOpportunity::sole()->market)->toBe(Market::Total)
        ->and((float) ArbitrageOpportunity::sole()->line)->toBe(45.5);
});
