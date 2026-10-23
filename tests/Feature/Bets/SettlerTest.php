<?php

declare(strict_types=1);

use App\Models\UserBet;
use App\Models\VlEvent;
use App\Models\VlOddsDisplay;
use App\Services\Bets\BetLedger;
use App\Services\Bets\BetSettler;

function finishedGame(int $home, int $away, int $id = 77): VlEvent
{
    return VlEvent::create([
        'edge_lab_id' => $id,
        'sport' => 'nfl',
        'starts_at' => now()->subHours(4),
        'status' => 'final',
        'home_name' => 'Kansas City Chiefs',
        'away_name' => 'Buffalo Bills',
        'final_home_score' => $home,
        'final_away_score' => $away,
        'completed_at' => now()->subHour(),
        'payload' => [],
        'digest' => 'e'.$id,
        'observed_at' => now(),
    ]);
}

function betOn(array $attributes = []): UserBet
{
    return app(BetLedger::class)->manual(betUser(), array_merge([
        'sport' => 'nfl',
        'market' => 'h2h',
        'side' => 'home',
        'selection' => 'Chiefs',
        'price_american' => -110,
        'stake_cents' => 1000,
        'edge_lab_event_id' => 77,
    ], $attributes));
}

it('grades a moneyline from the final score', function () {
    finishedGame(home: 27, away: 24);
    $bet = betOn();

    app(BetSettler::class)->run();

    expect($bet->refresh()->status)->toBe('won');
});

it('calls a tied moneyline a push rather than a loss', function () {
    finishedGame(home: 20, away: 20);
    $bet = betOn();

    app(BetSettler::class)->run();

    // It happens in the NFL, and grading it as a loss for both sides
    // would be wrong for both of them.
    expect($bet->refresh()->status)->toBe('push');
});

it('grades a spread against the number', function (float $line, int $home, int $away, string $expected) {
    finishedGame(home: $home, away: $away);
    $bet = betOn(['market' => 'spreads', 'side' => 'home', 'line' => $line]);

    app(BetSettler::class)->run();

    expect($bet->refresh()->status)->toBe($expected);
})->with([
    // Home wins by 3 against a 3.5-point handicap: not covered.
    [-3.5, 27, 24, 'lost'],
    [-2.5, 27, 24, 'won'],
    // Exactly on the number. This is why books sell half-points.
    [-3.0, 27, 24, 'push'],
    [3.5, 24, 27, 'won'],
]);

it('grades a total against the number', function (string $side, float $line, string $expected) {
    finishedGame(home: 27, away: 24);
    $bet = betOn(['market' => 'totals', 'side' => $side, 'line' => $line]);

    app(BetSettler::class)->run();

    expect($bet->refresh()->status)->toBe($expected);
})->with([
    ['over', 50.5, 'won'],
    ['over', 51.5, 'lost'],
    ['under', 51.5, 'won'],
    ['under', 51.0, 'push'],
]);

it('leaves a bet pending when the game has no final score', function () {
    VlEvent::create([
        'edge_lab_id' => 77, 'sport' => 'nfl', 'starts_at' => now()->addHour(),
        'status' => 'scheduled', 'payload' => [], 'digest' => 'e77', 'observed_at' => now(),
    ]);

    $bet = betOn();

    $result = app(BetSettler::class)->run();

    // Guessing at an outcome to clear a queue is how a record stops
    // being one.
    expect($bet->refresh()->status)->toBe('pending')
        ->and($result['skipped'])->toBe(1)
        ->and($result['settled'])->toBe(0);
});

it('leaves a market it cannot grade pending rather than calling it a loss', function () {
    finishedGame(home: 27, away: 24);
    $bet = betOn(['market' => 'player_points', 'side' => null]);

    $result = app(BetSettler::class)->run();

    // A market nobody wrote a rule for should show up as an ungraded
    // bet, not as a loss.
    expect($bet->refresh()->status)->toBe('pending')
        ->and($result['skipped'])->toBe(1);
});

it('leaves a manual bet with no event pending rather than guessing', function () {
    $bet = betOn(['edge_lab_event_id' => null]);

    app(BetSettler::class)->run();

    expect($bet->refresh()->status)->toBe('pending');
});

/* ----------------------------------------------------------------------
 | Closing line value
 ---------------------------------------------------------------------- */

it('records the closing price and the value against it', function () {
    $event = finishedGame(home: 27, away: 24);

    // Quoted before kickoff, which is what "closing" means.
    VlOddsDisplay::create([
        'edge_lab_event_id' => 77, 'book' => 'draftkings', 'book_title' => 'DraftKings',
        'market' => 'h2h', 'side' => 'home', 'price_american' => -130,
        'quoted_at' => $event->starts_at->copy()->subMinutes(5),
        'digest' => 'q1', 'observed_at' => now(),
    ]);

    $bet = betOn(['price_american' => -110, 'book' => 'draftkings']);

    app(BetSettler::class)->run();

    $bet->refresh();

    // The price shortened from -110 to -130, so the market came to agree
    // and the bet was ahead of it. Positive CLV.
    expect($bet->closing_price_american)->toBe(-130)
        ->and($bet->clv)->toBeGreaterThan(0);
});

it('calls it negative when the market moved away from the bet', function () {
    $event = finishedGame(home: 27, away: 24);

    VlOddsDisplay::create([
        'edge_lab_event_id' => 77, 'book' => 'draftkings', 'book_title' => 'DraftKings',
        'market' => 'h2h', 'side' => 'home', 'price_american' => 120,
        'quoted_at' => $event->starts_at->copy()->subMinutes(5),
        'digest' => 'q1', 'observed_at' => now(),
    ]);

    $bet = betOn(['price_american' => -110, 'book' => 'draftkings']);

    app(BetSettler::class)->run();

    // A bet that beat the close and lost had variance; one that lost to
    // the close and won got lucky. Only one of those is repeatable.
    expect($bet->refresh()->clv)->toBeLessThan(0);
});

it('ignores a quote taken after kickoff', function () {
    $event = finishedGame(home: 27, away: 24);

    VlOddsDisplay::create([
        'edge_lab_event_id' => 77, 'book' => 'draftkings', 'book_title' => 'DraftKings',
        'market' => 'h2h', 'side' => 'home', 'price_american' => -400,
        'quoted_at' => $event->starts_at->copy()->addMinutes(30),
        'digest' => 'q1', 'observed_at' => now(),
    ]);

    $bet = betOn(['book' => 'draftkings']);

    app(BetSettler::class)->run();

    // An in-play price is not a closing price, and -400 at half time
    // would report an enormous and entirely false edge.
    expect($bet->refresh()->closing_price_american)->toBeNull()
        ->and($bet->clv)->toBeNull();
});

it('leaves closing line value unmeasured rather than estimating it', function () {
    finishedGame(home: 27, away: 24);

    $bet = betOn();

    app(BetSettler::class)->run();

    // No stored quote, so no figure. A CLV computed from a price nobody
    // wrote down at the time would be a fabricated measurement of
    // exactly the thing this product sells.
    expect($bet->refresh()->status)->toBe('won')
        ->and($bet->clv)->toBeNull();
});
