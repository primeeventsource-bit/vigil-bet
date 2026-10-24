<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\Side;
use App\Enums\Sport;
use App\Models\Book;
use App\Models\Event;
use App\Models\EventLineMarker;
use App\Models\Team;
use App\Services\Modeling\TrainingSetBuilder;

function makeTeam(string $slug): Team
{
    return Team::create(['sport' => 'nfl', 'name' => $slug, 'display_name' => $slug, 'slug' => $slug]);
}

function trainedGame(string $startsAt, int $home = 24, int $away = 17, string $status = 'final'): Event
{
    static $n = 0;
    $n++;

    return Event::create([
        'sport' => 'nfl',
        'home_team_id' => makeTeam("home-{$n}")->id,
        'away_team_id' => makeTeam("away-{$n}")->id,
        'starts_at' => $startsAt,
        'season' => 2024,
        'status' => $status,
        'final_home_score' => $status === 'final' ? $home : null,
        'final_away_score' => $status === 'final' ? $away : null,
    ]);
}

function closeAt(Event $event, Book $book, int $homePrice, ?int $awayPrice): void
{
    foreach ([[Side::Home, $homePrice], [Side::Away, $awayPrice]] as [$side, $price]) {
        if ($price === null) {
            continue;
        }

        EventLineMarker::create([
            'event_id' => $event->id,
            'book_id' => $book->id,
            'market' => Market::Moneyline->value,
            'side' => $side->value,
            'price_close_american' => $price,
            'computed_at' => now(),
        ]);
    }
}

beforeEach(fn () => $this->builder = app(TrainingSetBuilder::class));

it('yields completed games in the order they were played', function () {
    trainedGame('2024-10-20 17:00');
    trainedGame('2024-09-08 13:00');
    trainedGame('2024-09-15 13:00');

    $games = iterator_to_array($this->builder->games(Sport::Nfl), false);
    $dates = array_map(fn ($g) => $g->startsAt->toDateString(), $games);

    expect($dates)->toBe(['2024-09-08', '2024-09-15', '2024-10-20']);
});

it('leaves out games that have not finished', function () {
    trainedGame('2024-09-08 13:00');
    trainedGame('2024-09-15 13:00', status: 'scheduled');

    expect(iterator_to_array($this->builder->games(Sport::Nfl), false))->toHaveCount(1);
});

it('attaches the closing market', function () {
    $event = trainedGame('2024-09-08 13:00');
    closeAt($event, Book::create(['key' => 'draftkings', 'title' => 'DK']), -140, 120);

    $game = iterator_to_array($this->builder->games(Sport::Nfl), false)[0];

    // toEqual, not toBe: this is a map keyed by side, and the order the
    // rows happen to come back in carries no meaning.
    expect($game->hasClosingMarket())->toBeTrue()
        ->and($game->closingMarket)->toEqual(['home' => -140, 'away' => 120]);
});

it('takes one book rather than averaging several', function () {
    // Averaging implied probabilities across books blends away the
    // overround and can produce a market summing under 100 percent, which
    // no book ever offered. One real book is the honest benchmark.
    $event = trainedGame('2024-09-08 13:00');
    closeAt($event, Book::create(['key' => 'betmgm', 'title' => 'MGM']), -150, 130);
    closeAt($event, Book::create(['key' => 'hardrockbet', 'title' => 'Hard Rock']), -130, 110);

    $game = iterator_to_array($this->builder->games(Sport::Nfl), false)[0];

    // Hard Rock is first in the preference order.
    expect($game->closingMarket)->toEqual(['home' => -130, 'away' => 110]);
});

it('falls back to a lesser book when the preferred one is incomplete', function () {
    $event = trainedGame('2024-09-08 13:00');
    closeAt($event, Book::create(['key' => 'hardrockbet', 'title' => 'Hard Rock']), -130, null);
    closeAt($event, Book::create(['key' => 'betmgm', 'title' => 'MGM']), -150, 130);

    $game = iterator_to_array($this->builder->games(Sport::Nfl), false)[0];

    expect($game->closingMarket)->toEqual(['home' => -150, 'away' => 130]);
});

it('reports no market when only one side ever closed', function () {
    $event = trainedGame('2024-09-08 13:00');
    closeAt($event, Book::create(['key' => 'draftkings', 'title' => 'DK']), -140, null);

    $game = iterator_to_array($this->builder->games(Sport::Nfl), false)[0];

    expect($game->hasClosingMarket())->toBeFalse()
        ->and($game->closingMarket)->toBe([]);
});

it('counts what is available without loading it', function () {
    trainedGame('2024-09-08 13:00');
    trainedGame('2024-09-15 13:00');
    trainedGame('2024-09-22 13:00', status: 'scheduled');

    expect($this->builder->count(Sport::Nfl))->toBe(2);
});

it('never learns from a preseason game', function () {
    // The backfill asks its sources for the regular season and two of them
    // return preseason anyway - a quarter of the NHL history here. A
    // preseason result says who rested their starters, so a rating that
    // learns from it learns the wrong thing about team strength.
    trainedGame('2024-09-01 17:00:00')->forceFill(['season_type' => 'pre'])->save();
    trainedGame('2024-09-08 17:00:00')->forceFill(['season_type' => 'reg'])->save();

    $games = iterator_to_array(app(TrainingSetBuilder::class)->games(Sport::Nfl));

    expect($games)->toHaveCount(1)
        ->and(app(TrainingSetBuilder::class)->count(Sport::Nfl))->toBe(1);
});

it('keeps the postseason, which is the most competitive football there is', function () {
    trainedGame('2025-01-12 17:00:00')->forceFill(['season_type' => 'post'])->save();

    expect(app(TrainingSetBuilder::class)->count(Sport::Nfl))->toBe(1);
});

it('keeps a game whose feed never said what kind it was', function () {
    // Unknown is not preseason. Dropping nulls would silently lose whole
    // sports whose source does not state a season type at all.
    trainedGame('2024-10-06 17:00:00')->forceFill(['season_type' => null])->save();

    expect(app(TrainingSetBuilder::class)->count(Sport::Nfl))->toBe(1);
});
