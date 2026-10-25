<?php

declare(strict_types=1);

use App\Services\Ingestion\CfbdFootballSource;
use Illuminate\Support\Facades\Http;

/*
 * `yield from` passes the inner generator's keys to the caller, so each
 * inner generator starts again at 0 and any caller that keys the result -
 * collect(), iterator_to_array with keys preserved - keeps only the last
 * line for each index and silently drops the rest.
 *
 * The writers iterate with foreach, which does not key, so nothing was
 * ever lost in production. That is exactly what makes it worth a test:
 * the bug is invisible until someone reasonably writes
 * `collect($source->teamGameStats(2026))` and gets a tenth of a season
 * back with no error anywhere.
 */
beforeEach(function (): void {
    config()->set('edge.sources.cfbd.key', 'test-key');
    config()->set('edge.sources.cfbd.base_url', 'https://api.collegefootballdata.com');
});

function teamStatsPayload(int $gameId): array
{
    return [[
        'id' => $gameId,
        'teams' => [
            [
                'teamId' => 100, 'team' => 'Home '.$gameId, 'homeAway' => 'home', 'points' => 27,
                'stats' => [['category' => 'totalYards', 'stat' => '400']],
            ],
            [
                'teamId' => 200, 'team' => 'Away '.$gameId, 'homeAway' => 'away', 'points' => 24,
                'stats' => [['category' => 'totalYards', 'stat' => '350']],
            ],
        ],
    ]];
}

it('keeps every team line when the result is collected rather than iterated', function (): void {
    /*
     * A different game per week, so a line lost to a key collision is a
     * line that cannot be explained away as a duplicate.
     */
    $week = 0;

    Http::fake([
        'api.collegefootballdata.com/games/teams*' => function () use (&$week) {
            return Http::response(teamStatsPayload(900000 + (++$week)), 200);
        },
    ]);

    $lines = collect(app(CfbdFootballSource::class)->teamGameStats(2026));

    /*
     * Sixteen regular weeks plus one postseason request, two teams each.
     */
    expect($lines)->toHaveCount(34)
        ->and($lines->pluck('sourceEventId')->unique())->toHaveCount(17);
});

it('keeps every player line when the result is collected', function (): void {
    $week = 0;

    Http::fake([
        'api.collegefootballdata.com/games/players*' => function () use (&$week) {
            $id = 800000 + (++$week);

            return Http::response([[
                'id' => $id,
                'teams' => [[
                    'team' => 'Home '.$id,
                    'categories' => [
                        ['name' => 'passing', 'types' => [
                            ['name' => 'YDS', 'athletes' => [['id' => '1', 'name' => 'A', 'stat' => '200']]],
                        ]],
                        ['name' => 'rushing', 'types' => [
                            ['name' => 'YDS', 'athletes' => [['id' => '2', 'name' => 'B', 'stat' => '50']]],
                        ]],
                    ],
                ]],
            ]], 200);
        },
    ]);

    $lines = collect(app(CfbdFootballSource::class)->playerGameStats(2026));

    expect($lines)->toHaveCount(34)
        ->and($lines->pluck('statGroup')->unique()->sort()->values()->all())->toBe(['passing', 'rushing']);
});
