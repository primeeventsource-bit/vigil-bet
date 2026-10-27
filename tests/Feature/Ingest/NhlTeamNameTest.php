<?php

declare(strict_types=1);

use App\Enums\SeasonType;
use App\Services\Ingestion\NhlApiSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| The NHL feed's two name fields are not always disjoint
|--------------------------------------------------------------------------
|
| `placeName` and `commonName` were being joined with a space on the
| assumption that one is the city and the other is the nickname. Usually
| true. Utah arrived as "Utah" and "Utah Hockey Club" and came out as
| "Utah Utah Hockey Club", which resolves to no team - although `Utah
| Hockey Club` is itself a listed alias of the franchise, so the doubled
| word was the whole of the problem.
|
| It failed quietly, which is why it lasted: an unresolved name goes into
| `unmatched_names` rather than raising anything, so the games just never
| attach to a team and nothing is predicted for them. Sixteen sightings
| had accumulated for a team playing a real schedule.
|
*/

function nhlSchedulePayload(array $homeTeam, array $awayTeam): array
{
    return [
        'gameWeek' => [[
            'games' => [[
                'id' => 2026020001,
                'startTimeUTC' => '2026-10-28T23:00:00Z',
                'gameState' => 'FUT',
                'gameType' => 2,
                'homeTeam' => $homeTeam,
                'awayTeam' => $awayTeam,
            ]],
        ]],
    ];
}

function firstNhlGame(array $home, array $away)
{
    Http::fake([
        'api-web.nhle.com/*' => Http::response(nhlSchedulePayload($home, $away)),
    ]);

    $games = app(NhlApiSource::class)->games(
        CarbonImmutable::parse('2026-10-28'),
        CarbonImmutable::parse('2026-10-28'),
        2026,
        SeasonType::Regular,
    );

    foreach ($games as $game) {
        return $game;
    }

    return null;
}

it('does not repeat the place when the common name already carries it', function () {
    $game = firstNhlGame(
        ['id' => 59, 'abbrev' => 'UTA', 'placeName' => ['default' => 'Utah'], 'commonName' => ['default' => 'Utah Hockey Club']],
        ['id' => 10, 'abbrev' => 'TOR', 'placeName' => ['default' => 'Toronto'], 'commonName' => ['default' => 'Maple Leafs']],
    );

    expect($game->homeTeamName)->toBe('Utah Hockey Club')
        ->and($game->homeTeamName)->not->toBe('Utah Utah Hockey Club');
});

it('still joins a place and a nickname that are genuinely separate', function () {
    $game = firstNhlGame(
        ['id' => 10, 'abbrev' => 'TOR', 'placeName' => ['default' => 'Toronto'], 'commonName' => ['default' => 'Maple Leafs']],
        ['id' => 6, 'abbrev' => 'BOS', 'placeName' => ['default' => 'Boston'], 'commonName' => ['default' => 'Bruins']],
    );

    expect($game->homeTeamName)->toBe('Toronto Maple Leafs')
        ->and($game->awayTeamName)->toBe('Boston Bruins');
});

it('does not double an all-star roster that fills both fields the same way', function () {
    // Not a team anybody can resolve either way, but "Team Matthews" is
    // at least the name the feed meant, and the doubled form was noise in
    // the log that read like a different problem.
    $game = firstNhlGame(
        ['id' => 1, 'abbrev' => 'MAT', 'placeName' => ['default' => 'Team Matthews'], 'commonName' => ['default' => 'Team Matthews']],
        ['id' => 2, 'abbrev' => 'MCD', 'placeName' => ['default' => 'Team McDavid'], 'commonName' => ['default' => 'Team McDavid']],
    );

    expect($game->homeTeamName)->toBe('Team Matthews')
        ->and($game->awayTeamName)->toBe('Team McDavid');
});

it('falls back to whichever single field the feed gave', function () {
    $game = firstNhlGame(
        ['id' => 10, 'abbrev' => 'TOR', 'commonName' => ['default' => 'Maple Leafs']],
        ['id' => 6, 'abbrev' => 'BOS', 'placeName' => ['default' => 'Boston']],
    );

    expect($game->homeTeamName)->toBe('Maple Leafs')
        ->and($game->awayTeamName)->toBe('Boston');
});

it('resolves the fixed name against the real franchise', function () {
    // The point of the fix. `Utah Hockey Club` is a listed alias, so once
    // the name stops being doubled the franchise is found - which is what
    // makes those sixteen unmatched sightings a bug rather than a gap in
    // the team data.
    $aliases = collect(require database_path('data/teams_nhl.php'))
        ->firstWhere(fn (array $row) => $row[0] === 'UTA');

    expect($aliases)->not->toBeNull()
        ->and($aliases[4])->toContain('Utah Hockey Club');
});
