<?php

declare(strict_types=1);

/*
 * Shared live-feed fixtures.
 *
 * These live here rather than in a test file because two suites fake the
 * same feeds, and a helper that exists only when some other file happens to
 * have been loaded makes running one file on its own fail.
 */

function espnScoreboard(int $home, int $away, int $clock = 134, string $state = 'STATUS_IN_PROGRESS'): array
{
    return [
        'events' => [[
            'id' => '401547439',
            'competitions' => [[
                'status' => [
                    'clock' => $clock,
                    'displayClock' => '2:14',
                    'period' => 4,
                    'type' => ['name' => $state],
                ],
                'situation' => ['possession' => '2', 'downDistanceText' => '3rd & 7'],
                'competitors' => [
                    ['homeAway' => 'home', 'score' => (string) $home, 'team' => ['id' => '2', 'displayName' => 'Buffalo Bills']],
                    ['homeAway' => 'away', 'score' => (string) $away, 'team' => ['id' => '15', 'displayName' => 'Miami Dolphins']],
                ],
            ]],
        ]],
    ];
}

function nhlScore(int $home, int $away, string $state = 'LIVE'): array
{
    return [
        'games' => [[
            'id' => 2026020123,
            'gameState' => $state,
            'period' => 3,
            'clock' => ['secondsRemaining' => 254, 'timeRemaining' => '04:14', 'inIntermission' => false],
            'homeTeam' => ['score' => $home, 'name' => ['default' => 'Boston Bruins'], 'abbrev' => 'BOS'],
            'awayTeam' => ['score' => $away, 'name' => ['default' => 'Montreal Canadiens'], 'abbrev' => 'MTL'],
        ]],
    ];
}
