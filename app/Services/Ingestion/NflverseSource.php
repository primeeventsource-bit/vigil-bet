<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Data\HistoricalLine;
use App\Data\ScheduleGame;
use App\Enums\EventStatus;
use App\Enums\SeasonType;
use App\Enums\Sport;
use Carbon\CarbonImmutable;
use Generator;
use RuntimeException;

/**
 * nflverse games.csv: every NFL game since 1999 with scores, rest days,
 * roof, surface, temperature, wind - and closing spreads, totals and
 * moneylines. That last part is what lets the NFL backtest be measured
 * against the real market instead of against nothing.
 *
 * The file is a couple of megabytes, so it is streamed from disk rather than
 * decoded into one array.
 */
class NflverseSource implements ProvidesHistoricalLines, SportDataSource
{
    use MakesHttpRequests;

    private const CSV_URL = 'https://raw.githubusercontent.com/nflverse/nfldata/master/data/games.csv';

    private const CACHE_MINUTES = 180;

    public function key(): string
    {
        return 'nflverse';
    }

    public function sport(): Sport
    {
        return Sport::Nfl;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function reliability(): SourceReliability
    {
        return SourceReliability::Community;
    }

    public function caveats(): ?string
    {
        return 'Community dataset. Betting lines are closing lines as collected by nflverse, not our own snapshots.';
    }

    public function schedule(int $season, SeasonType $seasonType = SeasonType::Regular): iterable
    {
        foreach ($this->rows() as $row) {
            if ((int) $row['season'] !== $season) {
                continue;
            }

            if ($this->seasonType($row['game_type']) !== $seasonType) {
                continue;
            }

            yield $this->toScheduleGame($row);
        }
    }

    public function games(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        foreach ($this->rows() as $row) {
            if ($row['gameday'] === '') {
                continue;
            }

            $day = CarbonImmutable::parse($row['gameday']);

            if ($day->betweenIncluded($from->startOfDay(), $to->endOfDay())) {
                yield $this->toScheduleGame($row);
            }
        }
    }

    /** @return Generator<int, HistoricalLine> */
    public function historicalLines(int $season): Generator
    {
        foreach ($this->rows() as $row) {
            if ((int) $row['season'] !== $season) {
                continue;
            }

            $spreadLine = $this->floatOrNull($row['spread_line']);

            $line = new HistoricalLine(
                sport: Sport::Nfl,
                sourceEventId: $row['game_id'],
                homeMoneylineAmerican: $this->intOrNull($row['home_moneyline']),
                awayMoneylineAmerican: $this->intOrNull($row['away_moneyline']),
                // nflverse prints a positive spread_line when the home team is
                // favored; books print that as a negative home spread.
                homeSpread: $spreadLine === null ? null : -$spreadLine,
                homeSpreadOddsAmerican: $this->intOrNull($row['home_spread_odds']),
                awaySpreadOddsAmerican: $this->intOrNull($row['away_spread_odds']),
                totalLine: $this->floatOrNull($row['total_line']),
                overOddsAmerican: $this->intOrNull($row['over_odds']),
                underOddsAmerican: $this->intOrNull($row['under_odds']),
                provenance: 'nflverse_games_csv',
            );

            if ($line->hasAnyPrice()) {
                yield $line;
            }
        }
    }

    private function toScheduleGame(array $row): ScheduleGame
    {
        $played = $row['home_score'] !== '' && $row['away_score'] !== '';

        return new ScheduleGame(
            sport: Sport::Nfl,
            sourceEventId: $row['game_id'],
            homeTeamName: $row['home_team'],
            awayTeamName: $row['away_team'],
            startsAt: $this->kickoff($row),
            season: (int) $row['season'],
            seasonType: $this->seasonType($row['game_type']),
            week: $this->intOrNull($row['week']),
            status: $played ? EventStatus::Final : EventStatus::Scheduled,
            homeScore: $played ? (int) $row['home_score'] : null,
            awayScore: $played ? (int) $row['away_score'] : null,
            homeTeamExternalId: $row['home_team'],
            awayTeamExternalId: $row['away_team'],
            venueName: $row['stadium'] !== '' ? $row['stadium'] : null,
            neutralSite: mb_strtolower($row['location']) === 'neutral',
            wentOvertime: $row['overtime'] === '1',
            // Rest, roof, surface and division are all set by the schedule,
            // so they are known long before kickoff. Game-time temp and wind
            // are in this file too and are deliberately not taken: they are
            // recorded after the fact, and training on them would be
            // leakage wearing a weather costume.
            homeRestDays: $this->intOrNull($row['home_rest'] ?? ''),
            awayRestDays: $this->intOrNull($row['away_rest'] ?? ''),
            roof: ($row['roof'] ?? '') !== '' ? $row['roof'] : null,
            surface: ($row['surface'] ?? '') !== '' ? $row['surface'] : null,
            divisional: ($row['div_game'] ?? '') === '1',
        );
    }

    /**
     * gameday is a date and gametime is local Eastern clock time. Old rows
     * have no gametime, so they fall back to the traditional 1pm ET slot.
     */
    private function kickoff(array $row): CarbonImmutable
    {
        $time = $row['gametime'] !== '' ? $row['gametime'] : '13:00';

        return CarbonImmutable::parse($row['gameday'].' '.$time, 'America/New_York')->utc();
    }

    private function seasonType(string $gameType): SeasonType
    {
        return match (mb_strtoupper($gameType)) {
            'PRE' => SeasonType::Preseason,
            'REG' => SeasonType::Regular,
            default => SeasonType::Postseason,
        };
    }

    /**
     * Streams the CSV as associative rows. Cached on local disk so a backfill
     * over many seasons does not re-download it for every season.
     *
     * @return Generator<int, array<string, string>>
     */
    private function rows(): Generator
    {
        $path = $this->cachedCsvPath();
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to read nflverse cache at {$path}.");
        }

        try {
            // Every argument stated, including the escape character. PHP
            // 8.4 deprecated calling this without it and PHP 9 changes the
            // default, so the silent version is both noisy now - a warning
            // per row, and these files run to thousands - and a behaviour
            // change later. Empty string is the new default and the right
            // one for CSV: a backslash is data in these files, not an
            // escape, and treating it as one merges fields.
            $header = fgetcsv($handle, 0, ',', '"', '');

            if ($header === false) {
                return;
            }

            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($row === [null] || count($row) !== count($header)) {
                    continue;
                }

                yield array_combine($header, array_map(static fn ($v) => (string) $v, $row));
            }
        } finally {
            fclose($handle);
        }
    }

    private function cachedCsvPath(): string
    {
        $path = storage_path('app/nflverse/games.csv');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $fresh = file_exists($path)
            && filemtime($path) > now()->subMinutes(self::CACHE_MINUTES)->getTimestamp();

        if (! $fresh) {
            file_put_contents($path, $this->getBody(self::CSV_URL));
        }

        return $path;
    }

    private function intOrNull(string $value): ?int
    {
        return $value === '' || $value === 'NA' ? null : (int) $value;
    }

    private function floatOrNull(string $value): ?float
    {
        return $value === '' || $value === 'NA' ? null : (float) $value;
    }
}
