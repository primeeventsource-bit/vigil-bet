<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The nflverse games.csv cache
|--------------------------------------------------------------------------
|
| Shared because more than one test file needs it, and because the cache
| is a real file in storage: a test that writes it and does not put it
| back leaves the next one reading somebody else's fixture.
|
| The columns are the ones the source actually reads, in the order the
| live file has them.
|
*/

const NFLVERSE_HEADER = 'game_id,season,game_type,week,gameday,weekday,gametime,away_team,away_score,home_team,home_score,location,result,total,overtime,stadium,roof,surface,div_game,home_rest,away_rest,spread_line,total_line,home_moneyline,away_moneyline,home_spread_odds,away_spread_odds,over_odds,under_odds';

function nflverseCsvPath(): string
{
    return storage_path('app/nflverse/games.csv');
}

/** One game. Empty scores are how the file reads before kickoff. */
function nflverseCsv(string $awayScore, string $homeScore): string
{
    return NFLVERSE_HEADER."\n"
        ."2026_07_CLE_TEN,2026,REG,7,2026-10-25,Sunday,13:00,CLE,{$awayScore},TEN,{$homeScore},Home,,,0,Nissan Stadium,outdoors,grass,0,7,7,-3.0,43.5,-135,115,-110,-110,-110,-110\n";
}

/** Put a copy on disk and age it, so the freshness check has something to decide about. */
function nflverseCacheAgedMinutes(string $csv, int $minutes): string
{
    $path = nflverseCsvPath();

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }

    file_put_contents($path, $csv);
    touch($path, time() - ($minutes * 60));

    return $path;
}

/** @return array{0: ?string, 1: ?int} */
function nflverseCacheSave(): array
{
    $path = nflverseCsvPath();

    return file_exists($path)
        ? [file_get_contents($path), filemtime($path)]
        : [null, null];
}

/** @param  array{0: ?string, 1: ?int}  $saved */
function nflverseCacheRestore(array $saved): void
{
    [$body, $time] = $saved;
    $path = nflverseCsvPath();

    if ($body === null) {
        @unlink($path);

        return;
    }

    file_put_contents($path, $body);
    touch($path, (int) $time);
}
