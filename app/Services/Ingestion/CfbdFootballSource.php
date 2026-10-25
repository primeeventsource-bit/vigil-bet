<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Data\ScheduleGame;
use App\Data\TeamGameStatLine;
use App\Enums\EventStatus;
use App\Enums\SeasonType;
use App\Enums\Sport;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * CollegeFootballData API for NCAAF. Documented and reliable, but needs a
 * free API key (CFBD_API_KEY). Without the key this source reports itself
 * unavailable rather than failing mid-pipeline.
 *
 * CFBD moved from snake_case to camelCase keys between major versions, so
 * every field is read through a helper that accepts either.
 */
class CfbdFootballSource implements ProvidesTeamGameStats, SportDataSource
{
    use MakesHttpRequests;

    /** Quarters in a regulation football game. A fifth period is overtime. */
    private const REGULATION_PERIODS = 4;

    public function key(): string
    {
        return 'cfbd';
    }

    public function sport(): Sport
    {
        return Sport::Ncaaf;
    }

    public function isAvailable(): bool
    {
        return filled(config('edge.sources.cfbd.key'));
    }

    public function reliability(): SourceReliability
    {
        return SourceReliability::DocumentedPublic;
    }

    public function caveats(): ?string
    {
        return 'Requires a free CFBD API key. Free tier is rate limited.';
    }

    public function schedule(int $season, SeasonType $seasonType = SeasonType::Regular): iterable
    {
        return $this->streamGames([
            'year' => $season,
            'seasonType' => $this->seasonTypeParam($seasonType),
        ], $seasonType);
    }

    /**
     * Games that started inside a window.
     *
     * The hourly results pull asks about the last few days. Doing that by
     * downloading a whole season and filtering in PHP measured at 442
     * seconds a run in production, with 8 of 19 runs dying on max
     * attempts - the games the job existed to read were the ones it could
     * not finish reading.
     *
     * So the calendar narrows it first. Sixteen rows describe a season,
     * one per week with a start and an end, and only the weeks the window
     * touches get fetched. A whole-season query remains as the fallback,
     * because a calendar that cannot be read is a reason to be slow and
     * not a reason to return nothing.
     */
    public function games(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $weeks = $this->weeksCovering($from, $to);

        $queries = $weeks === []
            ? [[(int) $from->year, null, SeasonType::Regular]]
            : $weeks;

        foreach ($queries as [$season, $week, $seasonType]) {
            $query = ['year' => $season];

            if ($week !== null) {
                $query['week'] = $week;
                $query['seasonType'] = $this->seasonTypeParam($seasonType);
            }

            foreach ($this->streamGames($query, $seasonType) as $game) {
                if ($game->startsAt->betweenIncluded($from, $to)) {
                    yield $game;
                }
            }
        }
    }

    /**
     * The season weeks a window overlaps, from CFBD's calendar.
     *
     * Cached for six hours. A season's week boundaries are fixed once the
     * schedule is published, so re-reading them every hourly pull would
     * spend a request to be told the same sixteen rows.
     *
     * Returns an empty array when the calendar cannot be read or covers
     * none of the window, which the caller treats as "fall back".
     *
     * @return array<int, array{0: int, 1: int, 2: SeasonType}>
     */
    private function weeksCovering(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $weeks = [];

        /*
         * A window can straddle New Year, where a January bowl game
         * belongs to the previous season. Both years are asked.
         */
        foreach (range((int) $from->year, (int) $to->year) as $year) {
            foreach ($this->calendar($year) as $row) {
                $start = $row['start'] ?? null;
                $end = $row['end'] ?? null;

                if ($start === null || $end === null) {
                    continue;
                }

                if ($start->lessThanOrEqualTo($to) && $end->greaterThanOrEqualTo($from)) {
                    $weeks[$year.':'.$row['seasonType']->value.':'.$row['week']]
                        = [$row['season'], $row['week'], $row['seasonType']];
                }
            }
        }

        return array_values($weeks);
    }

    /**
     * One season's week boundaries.
     *
     * @return array<int, array{season: int, week: int, seasonType: SeasonType, start: ?CarbonImmutable, end: ?CarbonImmutable}>
     */
    private function calendar(int $year): array
    {
        return Cache::remember(
            "cfbd:calendar:{$year}",
            now()->addHours(6),
            function () use ($year): array {
                try {
                    $rows = $this->getJson($this->url('calendar'), ['year' => $year], [
                        'Authorization' => 'Bearer '.config('edge.sources.cfbd.key'),
                    ]);
                } catch (Throwable) {
                    /*
                     * Not cached as a failure for six hours: an empty
                     * array is what Cache::remember declines to store, so
                     * the next pull tries the calendar again rather than
                     * being stuck on the slow path until the key expires.
                     */
                    return [];
                }

                $weeks = [];

                foreach ($rows as $row) {
                    if (! is_array($row) || ! isset($row['week'])) {
                        continue;
                    }

                    $weeks[] = [
                        'season' => (int) ($this->field($row, 'season', 'season') ?? $year),
                        'week' => (int) $row['week'],
                        'seasonType' => $this->seasonTypeFrom(
                            $this->field($row, 'season_type', 'seasonType'),
                            SeasonType::Regular,
                        ),
                        'start' => $this->date($this->field($row, 'start_date', 'startDate')),
                        'end' => $this->date($this->field($row, 'end_date', 'endDate')),
                    ];
                }

                return $weeks;
            },
        );
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Games from one CFBD query, as ScheduleGame objects.
     *
     * Not called fetch(). MakesHttpRequests has a fetch() and a class
     * method silently wins over a trait's, with no warning and no error -
     * so naming this one fetch() meant getJson() called straight back into
     * here with a URL string where this wanted an array. Every CFBD
     * request was impossible, and nothing noticed for as long as there was
     * no key to make one with.
     *
     * @return Generator<int, ScheduleGame>
     */
    /**
     * Box scores for a season, two rows per game.
     *
     * CFBD returns one object per game holding a `teams` array, each
     * entry carrying that side's points and a flat list of
     * {category, stat} pairs - `rushingYards`, `turnovers`,
     * `thirdDownEff` and so on, around thirty of them. The categories
     * are passed through under their own names rather than mapped to
     * columns: a box score's contents differ by sport and change
     * upstream, and the feature builder reads the few it understands.
     *
     * Points allowed is taken from the other side of the same game
     * rather than from a second request. It is the one derived figure
     * worth storing here, because a defensive rating needs it and
     * recomputing it later means joining a table to itself.
     *
     * @return Generator<int, TeamGameStatLine>
     */
    public function teamGameStats(int $season, ?int $week = null): Generator
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('CFBD API key is not configured.');
        }

        /*
         * CFBD will not serve a whole season of box scores at once:
         * without a week, team or conference it answers 400. So a
         * request for a season is a request for each of its weeks.
         *
         * Sixteen covers a regular season including championship week,
         * and the postseason is its own season type with one week in it.
         * Weeks that were never played return nothing rather than
         * failing, so the range does not need to know when the season
         * ended.
         */
        if ($week === null) {
            foreach (range(1, 16) as $each) {
                yield from $this->teamGameStats($season, $each);
            }

            yield from $this->postseasonTeamGameStats($season);

            return;
        }

        yield from $this->boxScores([
            'year' => $season,
            'week' => $week,
            'seasonType' => 'regular',
        ]);
    }

    /**
     * Bowls and the playoff, which are a separate season type.
     *
     * Excluded from the loop above rather than folded into it because
     * CFBD numbers postseason weeks from one again, so week 1 means two
     * different things depending on the type.
     *
     * @return Generator<int, TeamGameStatLine>
     */
    private function postseasonTeamGameStats(int $season): Generator
    {
        yield from $this->boxScores([
            'year' => $season,
            'week' => 1,
            'seasonType' => 'postseason',
        ]);
    }

    /**
     * One request's worth of box scores.
     *
     * @param  array<string, mixed>  $query
     * @return Generator<int, TeamGameStatLine>
     */
    private function boxScores(array $query): Generator
    {
        // Same bearer header the schedule and results calls use. CFBD
        // answers 401 without it rather than an empty list.
        $rows = $this->getJson($this->url('/games/teams'), $query, [
            'Authorization' => 'Bearer '.config('edge.sources.cfbd.key'),
        ]);

        foreach ($rows as $game) {
            $gameId = $this->idOf($game, 'id', 'id');
            $sides = $game['teams'] ?? null;

            // Two sides or it is not a game, and points allowed would be
            // a guess. Skipped rather than written half-formed.
            if ($gameId === null || ! is_array($sides) || count($sides) !== 2) {
                continue;
            }

            $points = [];

            foreach ($sides as $index => $side) {
                $points[$index] = is_numeric($side['points'] ?? null) ? (int) $side['points'] : null;
            }

            foreach ($sides as $index => $side) {
                $name = $this->field($side, 'team', 'team');

                if (! is_string($name) || $name === '') {
                    continue;
                }

                $line = new TeamGameStatLine(
                    sport: Sport::Ncaaf,
                    sourceEventId: $gameId,
                    sourceTeamName: $name,
                    sourceTeamId: $this->idOf($side, 'team_id', 'teamId'),
                    isHome: $this->field($side, 'home_away', 'homeAway') === 'home',
                    points: $points[$index] ?? null,
                    pointsAllowed: $points[$index === 0 ? 1 : 0] ?? null,
                    stats: $this->categories($side),
                    source: $this->key(),
                );

                if ($line->hasContent()) {
                    yield $line;
                }
            }
        }
    }

    /**
     * The {category, stat} pairs, flattened to a map.
     *
     * Values stay as strings where CFBD sends strings: `thirdDownEff`
     * arrives as "7-14" and `possessionTime` as "31:04", and casting
     * those to numbers would turn 7-14 into 7. Converting them is the
     * feature builder's problem, where the meaning of each category is
     * known.
     *
     * @param  array<string, mixed>  $side
     * @return array<string, string|int|float>
     */
    private function categories(array $side): array
    {
        $stats = [];

        foreach ($side['stats'] ?? [] as $pair) {
            $category = $pair['category'] ?? null;
            $value = $pair['stat'] ?? null;

            if (! is_string($category) || $category === '' || $value === null) {
                continue;
            }

            $stats[$category] = is_scalar($value) ? $value : null;

            if ($stats[$category] === null) {
                unset($stats[$category]);
            }
        }

        return $stats;
    }

    private function streamGames(array $query, SeasonType $seasonType): Generator
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('CFBD_API_KEY is not set; NCAAF ingestion is unavailable.');
        }

        $rows = $this->getJson($this->url('games'), $query, [
            'Authorization' => 'Bearer '.config('edge.sources.cfbd.key'),
        ]);

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $home = $this->field($row, 'home_team', 'homeTeam');
            $away = $this->field($row, 'away_team', 'awayTeam');
            $start = $this->field($row, 'start_date', 'startDate');

            if ($home === null || $away === null || $start === null) {
                continue;
            }

            $homePoints = $this->field($row, 'home_points', 'homePoints');
            $awayPoints = $this->field($row, 'away_points', 'awayPoints');
            $completed = (bool) ($this->field($row, 'completed', 'completed') ?? false);

            $periods = $this->periods(
                $this->field($row, 'home_line_scores', 'homeLineScores'),
                $this->field($row, 'away_line_scores', 'awayLineScores'),
            );

            $final = $completed || $this->finishedOnTheBoard($periods, $homePoints, $awayPoints);

            yield new ScheduleGame(
                sport: Sport::Ncaaf,
                sourceEventId: (string) $row['id'],
                homeTeamName: (string) $home,
                awayTeamName: (string) $away,
                startsAt: CarbonImmutable::parse((string) $start),
                season: (int) ($this->field($row, 'season', 'season') ?? $query['year'] ?? 0),
                seasonType: $this->seasonTypeFrom($this->field($row, 'season_type', 'seasonType'), $seasonType),
                week: $this->field($row, 'week', 'week') !== null ? (int) $this->field($row, 'week', 'week') : null,
                status: $final ? EventStatus::Final : EventStatus::Scheduled,
                homeScore: $homePoints !== null ? (int) $homePoints : null,
                awayScore: $awayPoints !== null ? (int) $awayPoints : null,
                homeTeamExternalId: $this->idOf($row, 'home_id', 'homeId'),
                awayTeamExternalId: $this->idOf($row, 'away_id', 'awayId'),
                venueName: $this->field($row, 'venue', 'venue'),
                neutralSite: (bool) ($this->field($row, 'neutral_site', 'neutralSite') ?? false),
                wentOvertime: $final && count($periods ?? []) > self::REGULATION_PERIODS,
                periodScores: $periods,
            );
        }
    }

    /**
     * Quarter scores, paired home against away.
     *
     * CFBD sends two parallel arrays. They are zipped here rather than
     * stored side by side so a period is one row, which is what reading
     * a box score asks of them.
     *
     * @return array<int, array{num: int, home: ?int, away: ?int}>|null
     */
    private function periods(mixed $home, mixed $away): ?array
    {
        if (! is_array($home) || ! is_array($away) || $home === [] || $away === []) {
            return null;
        }

        $periods = [];

        for ($i = 0, $count = max(count($home), count($away)); $i < $count; $i++) {
            $periods[] = [
                'num' => $i + 1,
                'home' => isset($home[$i]) && is_numeric($home[$i]) ? (int) $home[$i] : null,
                'away' => isset($away[$i]) && is_numeric($away[$i]) ? (int) $away[$i] : null,
            ];
        }

        return $periods;
    }

    /**
     * Whether the board says the game is over, for a game CFBD has not
     * yet flagged.
     *
     * `completed` comes from a batch job that runs hours behind the
     * scoreboard: on 24 October 2026, seventeen week-8 games carried full
     * final scores with `completed: false`, one of them 27-24 with four
     * quarters of line scores, while weeks 5 to 7 were all flagged. So the
     * flag arrives eventually and the only cost of waiting for it is that
     * an opinion on a finished game cannot be graded for a few hours.
     *
     * The line scores settle it earlier and on stronger evidence than a
     * clock estimate would: every period of regulation played, the period
     * scores adding up to the totals on both sides, and someone ahead.
     *
     * Each condition rules out a way of being wrong. Fewer periods than
     * regulation means the game is still running. Periods that do not sum
     * to the total mean the two disagree, and a disagreement is not
     * evidence of anything. A tie at the end of regulation means overtime
     * is pending, and calling that game a draw would grade every opinion
     * on it against a result that never happened.
     *
     * @param  array<int, array{num: int, home: ?int, away: ?int}>|null  $periods
     */
    private function finishedOnTheBoard(?array $periods, mixed $homePoints, mixed $awayPoints): bool
    {
        if ($periods === null || count($periods) < self::REGULATION_PERIODS) {
            return false;
        }

        if (! is_numeric($homePoints) || ! is_numeric($awayPoints)) {
            return false;
        }

        $home = (int) $homePoints;
        $away = (int) $awayPoints;

        if ($home === $away) {
            return false;
        }

        foreach (['home', 'away'] as $side) {
            $scores = array_column($periods, $side);

            if (in_array(null, $scores, true)) {
                return false;
            }

            if (array_sum($scores) !== ($side === 'home' ? $home : $away)) {
                return false;
            }
        }

        return true;
    }

    private function field(array $row, string $snake, string $camel): mixed
    {
        return $row[$snake] ?? $row[$camel] ?? null;
    }

    private function idOf(array $row, string $snake, string $camel): ?string
    {
        $value = $this->field($row, $snake, $camel);

        return $value === null ? null : (string) $value;
    }

    private function seasonTypeFrom(mixed $value, SeasonType $fallback): SeasonType
    {
        return match (mb_strtolower((string) $value)) {
            'regular' => SeasonType::Regular,
            'postseason' => SeasonType::Postseason,
            'preseason' => SeasonType::Preseason,
            default => $fallback,
        };
    }

    private function seasonTypeParam(SeasonType $type): string
    {
        return match ($type) {
            SeasonType::Preseason => 'preseason',
            SeasonType::Postseason => 'postseason',
            SeasonType::Regular => 'regular',
        };
    }

    private function url(string $path): string
    {
        return rtrim((string) config('edge.sources.cfbd.base_url'), '/').'/'.ltrim($path, '/');
    }
}
