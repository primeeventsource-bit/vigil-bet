<?php

declare(strict_types=1);

use App\Data\ScheduleGame;
use App\Enums\EventStatus;
use App\Services\Ingestion\CfbdFootballSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/*
 * CFBD sets `completed` from a batch job that runs hours behind the
 * scoreboard. Measured on 24 October 2026: weeks 5 through 7 were marked
 * completed across every classification, while week 8 held 17 games that
 * carried full final scores and `completed: false` - including one that
 * reported 27-24 with four quarters of line scores.
 *
 * Waiting on that flag means an opinion on a finished game cannot be
 * graded until CFBD catches up. The line scores are the better evidence:
 * four quarters played and a winner on the board is a completed game no
 * matter what the flag says.
 */
beforeEach(function (): void {
    config()->set('edge.sources.cfbd.key', 'test-key');
    config()->set('edge.sources.cfbd.base_url', 'https://api.collegefootballdata.com');
});

function cfbdRow(array $overrides = []): array
{
    return array_merge([
        'id' => 401907532,
        'season' => 2026,
        'week' => 8,
        'seasonType' => 'regular',
        'startDate' => '2026-10-24T20:00:00.000Z',
        'completed' => false,
        'neutralSite' => false,
        'homeId' => 2000,
        'homeTeam' => 'Seton Hill',
        'homePoints' => 27,
        'homeLineScores' => [7, 7, 7, 6],
        'awayId' => 2001,
        'awayTeam' => 'Clarion',
        'awayPoints' => 24,
        'awayLineScores' => [3, 14, 0, 7],
    ], $overrides);
}

function cfbdGame(array $overrides = []): ?ScheduleGame
{
    Http::fake([
        'api.collegefootballdata.com/games*' => Http::response([cfbdRow($overrides)], 200),
    ]);

    $games = app(CfbdFootballSource::class)->games(
        CarbonImmutable::parse('2026-10-24T00:00:00Z'),
        CarbonImmutable::parse('2026-10-26T00:00:00Z'),
    );

    return collect($games)->first();
}

it('grades a game CFBD has scored but not yet flagged completed', function (): void {
    $game = cfbdGame();

    expect($game->status)->toBe(EventStatus::Final)
        ->and($game->isFinal())->toBeTrue()
        ->and($game->homeScore)->toBe(27)
        ->and($game->awayScore)->toBe(24);
});

it('carries the quarter scores through so the period detail is not lost', function (): void {
    expect(cfbdGame()->periodScores)->toBe([
        ['num' => 1, 'home' => 7, 'away' => 3],
        ['num' => 2, 'home' => 7, 'away' => 14],
        ['num' => 3, 'home' => 7, 'away' => 0],
        ['num' => 4, 'home' => 6, 'away' => 7],
    ]);
});

it('marks overtime when a fifth period was played', function (): void {
    $game = cfbdGame([
        'homePoints' => 34,
        'awayPoints' => 31,
        'homeLineScores' => [7, 7, 7, 6, 7],
        'awayLineScores' => [3, 14, 0, 7, 7],
    ]);

    expect($game->wentOvertime)->toBeTrue()
        ->and($game->status)->toBe(EventStatus::Final);
});

/*
 * The cases where inferring would be wrong. Each one holds at scheduled
 * and waits for CFBD, which is the correct answer for a game that has
 * not actually finished.
 */
it('holds a tie at the end of regulation, because overtime is pending', function (): void {
    expect(cfbdGame([
        'homePoints' => 24,
        'awayPoints' => 24,
        'homeLineScores' => [7, 7, 7, 3],
        'awayLineScores' => [3, 14, 0, 7],
    ])->status)->toBe(EventStatus::Scheduled);
});

it('holds a game still inside regulation', function (): void {
    expect(cfbdGame([
        'homePoints' => 21,
        'awayPoints' => 17,
        'homeLineScores' => [7, 7, 7],
        'awayLineScores' => [3, 14, 0],
    ])->status)->toBe(EventStatus::Scheduled);
});

it('holds a game whose quarters do not add up to its total', function (): void {
    expect(cfbdGame([
        'homePoints' => 41,
        'homeLineScores' => [7, 7, 7, 6],
    ])->status)->toBe(EventStatus::Scheduled);
});

it('holds a game with no line scores at all', function (): void {
    expect(cfbdGame([
        'homePoints' => null,
        'awayPoints' => null,
        'homeLineScores' => null,
        'awayLineScores' => null,
    ])->status)->toBe(EventStatus::Scheduled);
});

it('still trusts the flag when CFBD has caught up', function (): void {
    expect(cfbdGame([
        'completed' => true,
        'homeLineScores' => null,
        'awayLineScores' => null,
    ])->status)->toBe(EventStatus::Final);
});
