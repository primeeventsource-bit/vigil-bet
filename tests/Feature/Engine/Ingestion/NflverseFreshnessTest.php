<?php

declare(strict_types=1);

use App\Services\Ingestion\NflverseSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/*
 * One file, two different questions.
 *
 * nflverse's games.csv is both the historical record and the results
 * feed, and those want opposite things from a cache. The history is
 * settled and can be held for hours; the score columns fill in within an
 * hour of a game ending and are what grading waits on. Held for three
 * hours, a finished game reads as unplayed long after the score is
 * public - which is how an opinion on it sits `pending` on a public page
 * with the result available elsewhere.
 */

const NFLVERSE_HEADER = 'game_id,season,game_type,week,gameday,weekday,gametime,away_team,away_score,home_team,home_score,location,result,total,overtime,stadium,roof,surface,div_game,home_rest,away_rest,spread_line,total_line,home_moneyline,away_moneyline,home_spread_odds,away_spread_odds,over_odds,under_odds';

function nflverseCsv(string $awayScore, string $homeScore): string
{
    return NFLVERSE_HEADER."\n"
        ."2026_07_CLE_TEN,2026,REG,7,2026-10-25,Sunday,13:00,CLE,{$awayScore},TEN,{$homeScore},Home,,,0,Nissan Stadium,outdoors,grass,0,7,7,-3.0,43.5,-135,115,-110,-110,-110,-110\n";
}

function nflverseCacheAgedMinutes(string $csv, int $minutes): string
{
    $path = storage_path('app/nflverse/games.csv');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }

    file_put_contents($path, $csv);
    touch($path, time() - ($minutes * 60));

    return $path;
}

beforeEach(function () {
    $this->nflversePath = storage_path('app/nflverse/games.csv');
    $this->nflverseBackup = file_exists($this->nflversePath)
        ? file_get_contents($this->nflversePath)
        : null;
    $this->nflverseBackupTime = file_exists($this->nflversePath)
        ? filemtime($this->nflversePath)
        : null;
});

afterEach(function () {
    if ($this->nflverseBackup === null) {
        @unlink($this->nflversePath);

        return;
    }

    file_put_contents($this->nflversePath, $this->nflverseBackup);
    touch($this->nflversePath, $this->nflverseBackupTime);
});

it('re-reads the file for results once the score columns could have changed', function () {
    Http::fake([
        'raw.githubusercontent.com/*' => Http::response(nflverseCsv('27', '24')),
    ]);

    // Half an hour old: still fresh by the history window, stale by the
    // results one. The copy on disk is the one from before kickoff.
    nflverseCacheAgedMinutes(nflverseCsv('', ''), 30);

    $games = iterator_to_array(app(NflverseSource::class)->games(
        CarbonImmutable::parse('2026-10-25')->subDay(),
        CarbonImmutable::parse('2026-10-25')->addDay(),
    ));

    expect($games)->toHaveCount(1)
        ->and($games[0]->awayScore)->toBe(27)
        ->and($games[0]->homeScore)->toBe(24)
        ->and($games[0]->status->value)->toBe('final');

    Http::assertSentCount(1);
});

it('still holds the file for three hours for everything that is not a result', function () {
    Http::fake([
        'raw.githubusercontent.com/*' => Http::response(nflverseCsv('27', '24')),
    ]);

    nflverseCacheAgedMinutes(nflverseCsv('', ''), 30);

    $scheduled = iterator_to_array(app(NflverseSource::class)->schedule(2026));

    // A kickoff time, a roof and a rest day do not change, so a download
    // a season's worth of rows into a backfill is a cost with nothing
    // bought by it.
    expect($scheduled)->toHaveCount(1)
        ->and($scheduled[0]->homeScore)->toBeNull();

    Http::assertNothingSent();
});

it('downloads for results only once the short window has passed', function () {
    Http::fake([
        'raw.githubusercontent.com/*' => Http::response(nflverseCsv('27', '24')),
    ]);

    // Two minutes old. Inside the results window, so a results sync
    // running every hour does not re-download on every pass.
    nflverseCacheAgedMinutes(nflverseCsv('', ''), 2);

    $games = iterator_to_array(app(NflverseSource::class)->games(
        CarbonImmutable::parse('2026-10-25')->subDay(),
        CarbonImmutable::parse('2026-10-25')->addDay(),
    ));

    expect($games)->toHaveCount(1)
        ->and($games[0]->homeScore)->toBeNull();

    Http::assertNothingSent();
});
