<?php

declare(strict_types=1);

use App\Enums\Sport;
use App\Models\DepthChartEntry;
use App\Models\Team;
use App\Services\Ingestion\EspnDepthChartSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * A depth chart answers the question weighting a position cannot.
 *
 * Losing a first-choice quarterback and losing a fourth-choice one are
 * the same event to a head count and nearly the same to a position
 * weight. They are not the same to the team, and they are not priced
 * the same by the market.
 */

function espnTeamsPayload(string $id, string $name, string $abbr): array
{
    return ['sports' => [['leagues' => [['teams' => [
        ['team' => ['id' => $id, 'displayName' => $name, 'abbreviation' => $abbr]],
    ]]]]]];
}

function espnChartPayload(array $qbIds): array
{
    $athletes = [];

    foreach ($qbIds as $i => $id) {
        $athletes[] = [
            'slot' => 9,
            'rank' => $i + 1,
            'athlete' => ['$ref' => "http://sports.core.api.espn.com/v2/sports/football/leagues/nfl/seasons/2026/athletes/{$id}?lang=en"],
        ];
    }

    return ['items' => [[
        'id' => '21',
        'name' => '3WR 1TE',
        'positions' => [
            'qb' => ['position' => ['abbreviation' => 'QB'], 'athletes' => $athletes],
            // Deliberately present and deliberately ignored: the chart
            // carries the whole roster and only a few positions are
            // weighted, so storing the rest is noise with a cost.
            'lt' => ['position' => ['abbreviation' => 'LT'], 'athletes' => [
                ['slot' => 1, 'rank' => 1, 'athlete' => ['$ref' => 'http://x/athletes/999?lang=en']],
            ]],
        ],
    ]]];
}

function depthTeam(string $name = 'Philadelphia Eagles'): Team
{
    return Team::create([
        'sport' => 'nfl',
        'name' => $name,
        'display_name' => $name,
        'abbreviation' => 'PHI',
        // The resolver matches a normalised name against the slug, so
        // this is the name rather than a unique-looking variant of it.
        'slug' => Str::slug($name),
    ]);
}

it('reads a chart without asking the provider who anybody is', function () {
    depthTeam();

    Http::fake([
        '*/football/nfl/teams' => Http::response(espnTeamsPayload('21', 'Philadelphia Eagles', 'PHI')),
        '*/depthcharts*' => Http::response(espnChartPayload(['4685201', '4879250', '4360698', '4040715'])),
    ]);

    $stats = app(EspnDepthChartSource::class)->sync(Sport::Nfl);

    expect($stats['teams'])->toBe(1)
        ->and($stats['entries'])->toBe(4);

    /*
     * Four requests would have been needed for the names. They are not
     * made, because player_game_stats already stores the provider's
     * athlete id and that is the join.
     */
    Http::assertSentCount(2);

    expect(DepthChartEntry::query()->where('position', 'QB')->orderBy('rank')->pluck('player_external_id')->all())
        ->toBe(['4685201', '4879250', '4360698', '4040715']);
});

it('keeps only the positions the weighting actually uses', function () {
    depthTeam();

    Http::fake([
        '*/football/nfl/teams' => Http::response(espnTeamsPayload('21', 'Philadelphia Eagles', 'PHI')),
        '*/depthcharts*' => Http::response(espnChartPayload(['4685201'])),
    ]);

    app(EspnDepthChartSource::class)->sync(Sport::Nfl);

    expect(DepthChartEntry::query()->pluck('position')->unique()->all())->toBe(['QB']);
});

it('refuses to be edited or deleted once written', function () {
    depthTeam();

    Http::fake([
        '*/football/nfl/teams' => Http::response(espnTeamsPayload('21', 'Philadelphia Eagles', 'PHI')),
        '*/depthcharts*' => Http::response(espnChartPayload(['4685201'])),
    ]);

    app(EspnDepthChartSource::class)->sync(Sport::Nfl);

    $entry = DepthChartEntry::sole();

    // A chart is an observation. Editing one is editing what was known
    // before a game, which is the whole point of keeping it.
    expect(fn () => $entry->update(['rank' => 4]))->toThrow(RuntimeException::class, 'append-only')
        ->and(fn () => $entry->delete())->toThrow(RuntimeException::class, 'append-only');
});

it('will not read a chart fetched after kickoff', function () {
    $team = depthTeam();

    DepthChartEntry::create([
        'sport' => 'nfl',
        'team_id' => $team->id,
        'player_external_id' => '4040715',
        'position' => 'QB',
        'formation' => '3WR 1TE',
        'rank' => 1,
        'source' => 'espn',
        'as_of' => now()->addHours(3),
        'created_at' => now()->addHours(3),
    ]);

    /*
     * A team sheet published after the game was partly chosen because of
     * how the game was going. Reading one is how a backtest becomes
     * clairvoyant about exactly the fixtures where it mattered.
     */
    expect(DepthChartEntry::query()->asKnownBefore(now())->count())->toBe(0)
        ->and(DepthChartEntry::query()->asKnownBefore(now()->addHours(4))->count())->toBe(1);
});
