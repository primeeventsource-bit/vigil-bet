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
use RuntimeException;

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

    public function games(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        foreach ($this->streamGames(['year' => (int) $from->year], SeasonType::Regular) as $game) {
            if ($game->startsAt->betweenIncluded($from, $to)) {
                yield $game;
            }
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

        $query = array_filter([
            'year' => $season,
            'week' => $week,
            'seasonType' => 'regular',
        ], static fn ($value) => $value !== null);

        foreach ($this->getJson($this->url('/games/teams'), $query) as $game) {
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

            yield new ScheduleGame(
                sport: Sport::Ncaaf,
                sourceEventId: (string) $row['id'],
                homeTeamName: (string) $home,
                awayTeamName: (string) $away,
                startsAt: CarbonImmutable::parse((string) $start),
                season: (int) ($this->field($row, 'season', 'season') ?? $query['year'] ?? 0),
                seasonType: $this->seasonTypeFrom($this->field($row, 'season_type', 'seasonType'), $seasonType),
                week: $this->field($row, 'week', 'week') !== null ? (int) $this->field($row, 'week', 'week') : null,
                status: $completed ? EventStatus::Final : EventStatus::Scheduled,
                homeScore: $homePoints !== null ? (int) $homePoints : null,
                awayScore: $awayPoints !== null ? (int) $awayPoints : null,
                homeTeamExternalId: $this->idOf($row, 'home_id', 'homeId'),
                awayTeamExternalId: $this->idOf($row, 'away_id', 'awayId'),
                venueName: $this->field($row, 'venue', 'venue'),
                neutralSite: (bool) ($this->field($row, 'neutral_site', 'neutralSite') ?? false),
            );
        }
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
