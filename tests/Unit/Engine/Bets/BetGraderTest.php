<?php

declare(strict_types=1);

use App\Enums\BetStatus;
use App\Enums\Market;
use App\Enums\Side;
use App\Models\Bet;
use App\Models\Event;
use App\Services\Engine\Bets\BetGrader;

function scoredGame(?int $home, ?int $away, string $status = 'final'): Event
{
    return new Event([
        'sport' => 'nfl',
        'status' => $status,
        'final_home_score' => $home,
        'final_away_score' => $away,
    ]);
}

function wager(Market $market, Side $side, ?float $line = null): Bet
{
    return new Bet([
        'market' => $market->value,
        'side' => $side->value,
        'line' => $line,
        'price_american' => -110,
        'stake_cents' => 10000,
    ]);
}

beforeEach(function () {
    $this->grader = new BetGrader;
});

it('pays the moneyline to whoever won', function () {
    // Home 27, away 20.
    expect($this->grader->outcomeFor(wager(Market::Moneyline, Side::Home), scoredGame(27, 20)))->toBe(BetStatus::Won)
        ->and($this->grader->outcomeFor(wager(Market::Moneyline, Side::Away), scoredGame(27, 20)))->toBe(BetStatus::Lost);
});

it('refunds a moneyline on a tie', function () {
    // Rare, but the NFL regular season allows it and a tie is not a loss.
    expect($this->grader->outcomeFor(wager(Market::Moneyline, Side::Home), scoredGame(20, 20)))->toBe(BetStatus::Push);
});

it('covers a spread from the backed side point of view', function () {
    // Home by 7. A home favourite laying 3 covers; the dog getting 3 does not.
    expect($this->grader->outcomeFor(wager(Market::Spread, Side::Home, -3.0), scoredGame(27, 20)))->toBe(BetStatus::Won)
        ->and($this->grader->outcomeFor(wager(Market::Spread, Side::Away, 3.0), scoredGame(27, 20)))->toBe(BetStatus::Lost);
});

it('covers a spread for the underdog when the margin is short', function () {
    // Home by 2 with the dog getting 3.
    expect($this->grader->outcomeFor(wager(Market::Spread, Side::Away, 3.0), scoredGame(23, 21)))->toBe(BetStatus::Won)
        ->and($this->grader->outcomeFor(wager(Market::Spread, Side::Home, -3.0), scoredGame(23, 21)))->toBe(BetStatus::Lost);
});

it('pushes a spread landing exactly on the number', function () {
    // Home by 3 laying 3. Both sides get their money back, and treating
    // this as a loss would quietly cost real money on every whole number.
    expect($this->grader->outcomeFor(wager(Market::Spread, Side::Home, -3.0), scoredGame(23, 20)))->toBe(BetStatus::Push)
        ->and($this->grader->outcomeFor(wager(Market::Spread, Side::Away, 3.0), scoredGame(23, 20)))->toBe(BetStatus::Push);
});

it('cannot push a half point spread', function () {
    expect($this->grader->outcomeFor(wager(Market::Spread, Side::Home, -3.5), scoredGame(23, 20)))->toBe(BetStatus::Lost)
        ->and($this->grader->outcomeFor(wager(Market::Spread, Side::Away, 3.5), scoredGame(23, 20)))->toBe(BetStatus::Won);
});

it('grades a total against the combined score', function () {
    // 45 points against a 44.5 total.
    expect($this->grader->outcomeFor(wager(Market::Total, Side::Over, 44.5), scoredGame(27, 18)))->toBe(BetStatus::Won)
        ->and($this->grader->outcomeFor(wager(Market::Total, Side::Under, 44.5), scoredGame(27, 18)))->toBe(BetStatus::Lost);
});

it('pushes a total landing on the number', function () {
    expect($this->grader->outcomeFor(wager(Market::Total, Side::Over, 45.0), scoredGame(27, 18)))->toBe(BetStatus::Push);
});

it('voids a bet on a cancelled game', function () {
    expect($this->grader->outcomeFor(wager(Market::Moneyline, Side::Home), scoredGame(null, null, 'canceled')))
        ->toBe(BetStatus::Void);
});

it('leaves a postponed game pending', function () {
    // A postponed game is usually replayed and the bet usually stands, so
    // voiding it here would settle something that is about to have a result.
    expect($this->grader->outcomeFor(wager(Market::Moneyline, Side::Home), scoredGame(null, null, 'postponed')))
        ->toBeNull();
});

it('refuses to grade before the game is final', function () {
    expect($this->grader->outcomeFor(wager(Market::Moneyline, Side::Home), scoredGame(null, null, 'scheduled')))->toBeNull()
        ->and($this->grader->outcomeFor(wager(Market::Moneyline, Side::Home), scoredGame(14, 10, 'in_progress')))->toBeNull();
});

it('refuses to grade a final game with no score', function () {
    // The source said final and gave nothing. Guessing here corrupts the
    // track record, so the bet stays pending and visible.
    expect($this->grader->outcomeFor(wager(Market::Moneyline, Side::Home), scoredGame(null, null)))->toBeNull();
});

it('refuses to grade a spread or total with no line stored', function () {
    expect($this->grader->outcomeFor(wager(Market::Spread, Side::Home), scoredGame(27, 20)))->toBeNull()
        ->and($this->grader->outcomeFor(wager(Market::Total, Side::Over), scoredGame(27, 20)))->toBeNull();
});

it('refuses a side that makes no sense for the market', function () {
    expect($this->grader->outcomeFor(wager(Market::Total, Side::Home, 44.5), scoredGame(27, 18)))->toBeNull()
        ->and($this->grader->outcomeFor(wager(Market::Spread, Side::Over, -3.0), scoredGame(27, 18)))->toBeNull();
});

it('refuses to grade a custom bet', function () {
    // There is no event behind it to read a result from.
    $bet = wager(Market::Moneyline, Side::Home);
    $bet->is_custom = true;

    expect($this->grader->outcomeFor($bet, scoredGame(27, 20)))->toBeNull();
});
