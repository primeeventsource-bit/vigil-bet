<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Data\GameBoxScore;
use App\Data\PlayerGameStatLine;
use App\Data\ScheduleGame;
use App\Data\TeamGameStatLine;
use App\Enums\EventStatus;
use App\Enums\SeasonType;
use App\Enums\Sport;
use Carbon\CarbonImmutable;
use Generator;
use RuntimeException;

/**
 * ESPN public scoreboard. Undocumented and unsupported, but keyless and
 * currently the only free source that answers for NBA and NCAAB from a
 * server IP - the NBA-run endpoints refuse datacenter traffic.
 *
 * The scoreboard answers one day at a time; date ranges are rejected. A
 * season backfill therefore walks days, which is why backfill runs as
 * chunked queued jobs.
 *
 * Marked Unofficial on purpose: the Jobs screen shows that label so a silent
 * upstream change is visible rather than mistaken for a quiet week.
 */
class EspnSource implements ProvidesGameBoxScore, SportDataSource
{
    use MakesHttpRequests;

    private const BASE = 'https://site.api.espn.com/apis/site/v2/sports';

    public function __construct(private readonly Sport $sport) {}

    public function key(): string
    {
        return 'espn';
    }

    public function sport(): Sport
    {
        return $this->sport;
    }

    public function isAvailable(): bool
    {
        return $this->sport->config('espn_path') !== null;
    }

    public function reliability(): SourceReliability
    {
        return SourceReliability::Unofficial;
    }

    public function caveats(): ?string
    {
        return 'Undocumented ESPN endpoint, one day per request, no odds history.';
    }

    public function schedule(int $season, SeasonType $seasonType = SeasonType::Regular): iterable
    {
        [$from, $to] = $this->seasonWindow($season);

        return $this->games($from, $to, $seasonType);
    }

    /** @return Generator<int, ScheduleGame> */
    public function games(
        CarbonImmutable $from,
        CarbonImmutable $to,
        SeasonType $seasonType = SeasonType::Regular,
    ): Generator {
        if (! $this->isAvailable()) {
            throw new RuntimeException("No ESPN path configured for sport [{$this->sport->value}].");
        }

        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $payload = $this->getJson($this->url(), array_filter([
                'dates' => $day->format('Ymd'),
                'limit' => 500,
                'groups' => $this->sport->config('espn_groups'),
            ]));

            foreach ($payload['events'] ?? [] as $event) {
                $game = $this->toScheduleGame($event, $seasonType);

                if ($game !== null) {
                    yield $game;
                }
            }
        }
    }

    /**
     * Team and player lines for one game, from ESPN's summary endpoint.
     *
     * One request returns both, which is why this is a box score rather
     * than two methods: at one request per game, fetching the same
     * payload twice is the difference between a backfill that finishes
     * and one that does not.
     *
     * A game ESPN has nothing for comes back empty rather than throwing.
     * Postponed and abandoned fixtures are normal and a backfill should
     * walk past them.
     */
    public function gameBoxScore(string $sourceEventId): GameBoxScore
    {
        $payload = $this->getJson($this->summaryUrl(), ['event' => $sourceEventId]);
        $box = $payload['boxscore'] ?? null;

        if (! is_array($box)) {
            return new GameBoxScore($sourceEventId);
        }

        return new GameBoxScore(
            sourceEventId: $sourceEventId,
            teams: $this->teamLines($sourceEventId, $box),
            players: $this->playerLines($sourceEventId, $box),
        );
    }

    /**
     * One line per side, from `boxscore.teams`.
     *
     * @param  array<string, mixed>  $box
     * @return array<int, TeamGameStatLine>
     */
    private function teamLines(string $sourceEventId, array $box): array
    {
        $sides = array_values(array_filter(
            $box['teams'] ?? [],
            static fn ($side) => is_array($side) && isset($side['team'])
        ));

        // Two sides, or points allowed would be a guess.
        if (count($sides) !== 2) {
            return [];
        }

        $scores = [];

        foreach ($sides as $index => $side) {
            $scores[$index] = $this->statValue($side['statistics'] ?? [], 'points');
        }

        $lines = [];

        foreach ($sides as $index => $side) {
            $team = $side['team'] ?? [];
            $name = $team['displayName'] ?? $team['name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $line = new TeamGameStatLine(
                sport: $this->sport,
                sourceEventId: $sourceEventId,
                sourceTeamName: $name,
                sourceTeamId: isset($team['id']) ? (string) $team['id'] : null,
                isHome: ($side['homeAway'] ?? null) === 'home',
                points: $scores[$index],
                pointsAllowed: $scores[$index === 0 ? 1 : 0],
                stats: $this->flatStats($side['statistics'] ?? []),
                source: $this->key(),
            );

            if ($line->hasContent()) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * ESPN's team statistics, as {name: displayValue}.
     *
     * displayValue rather than value: field goals arrive as "41-83",
     * which is two numbers, and ESPN's `value` for those is the made
     * count alone - so reading it silently discards the attempts.
     * Splitting them is the feature builder's job, where the meaning of
     * each label is known.
     *
     * @param  array<int, mixed>  $statistics
     * @return array<string, string|int|float>
     */
    private function flatStats(array $statistics): array
    {
        $stats = [];

        foreach ($statistics as $stat) {
            if (! is_array($stat)) {
                continue;
            }

            $name = $stat['name'] ?? null;
            $value = $stat['displayValue'] ?? $stat['value'] ?? null;

            if (is_string($name) && $name !== '' && is_scalar($value)) {
                $stats[$name] = $value;
            }
        }

        return $stats;
    }

    /**
     * One number out of ESPN's statistics list, or null.
     *
     * @param  array<int, mixed>  $statistics
     */
    private function statValue(array $statistics, string $name): ?int
    {
        foreach ($statistics as $stat) {
            if (! is_array($stat) || ($stat['name'] ?? null) !== $name) {
                continue;
            }

            $value = $stat['displayValue'] ?? $stat['value'] ?? null;

            return is_numeric($value) ? (int) $value : null;
        }

        return null;
    }

    /**
     * Player lines, from `boxscore.players`.
     *
     * ESPN gives parallel arrays: `keys` naming the columns and each
     * athlete's `stats` holding the values in the same order. Football
     * files one group per discipline, so the same quarterback appears
     * under passing and rushing in one game - which is why the group
     * travels with the row and forms part of its identity.
     *
     * @param  array<string, mixed>  $box
     * @return array<int, PlayerGameStatLine>
     */
    private function playerLines(string $sourceEventId, array $box): array
    {
        $lines = [];

        foreach ($box['players'] ?? [] as $side) {
            if (! is_array($side)) {
                continue;
            }

            $team = $side['team'] ?? [];
            $teamName = $team['displayName'] ?? $team['name'] ?? null;
            $teamId = isset($team['id']) ? (string) $team['id'] : null;

            foreach ($side['statistics'] ?? [] as $group) {
                if (! is_array($group)) {
                    continue;
                }

                $labels = array_values(array_filter(
                    $group['keys'] ?? $group['labels'] ?? [],
                    static fn ($label) => is_string($label) && $label !== ''
                ));

                $groupName = is_string($group['name'] ?? null) && $group['name'] !== ''
                    ? $group['name']
                    : 'all';

                foreach ($group['athletes'] ?? [] as $entry) {
                    $line = $this->playerLine(
                        $sourceEventId,
                        $entry,
                        $labels,
                        $groupName,
                        is_string($teamName) ? $teamName : null,
                        $teamId,
                    );

                    if ($line !== null) {
                        $lines[] = $line;
                    }
                }
            }
        }

        return $lines;
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function playerLine(
        string $sourceEventId,
        mixed $entry,
        array $labels,
        string $groupName,
        ?string $teamName,
        ?string $teamId,
    ): ?PlayerGameStatLine {
        if (! is_array($entry)) {
            return null;
        }

        $athlete = $entry['athlete'] ?? [];
        $id = is_array($athlete) ? ($athlete['id'] ?? null) : null;
        $name = is_array($athlete) ? ($athlete['displayName'] ?? $athlete['shortName'] ?? null) : null;

        /*
         * No id means nothing downstream could ever join this row to the
         * same player in another game, which is the only reason to keep
         * a player line at all.
         */
        if ($id === null || ! is_string($name) || $name === '') {
            return null;
        }

        $values = array_values(array_filter(
            $entry['stats'] ?? [],
            static fn ($value) => is_scalar($value)
        ));

        $stats = [];

        foreach ($labels as $index => $label) {
            if (array_key_exists($index, $values)) {
                $stats[$label] = $values[$index];
            }
        }

        $line = new PlayerGameStatLine(
            sport: $this->sport,
            sourceEventId: $sourceEventId,
            playerExternalId: (string) $id,
            playerName: $name,
            sourceTeamName: $teamName,
            sourceTeamId: $teamId,
            position: $athlete['position']['abbreviation'] ?? null,
            played: (bool) ($entry['active'] ?? true),
            statGroup: $groupName,
            stats: $stats,
            source: $this->key(),
        );

        return $line->hasContent() ? $line : null;
    }

    private function summaryUrl(): string
    {
        return self::BASE.'/'.trim((string) $this->sport->config('espn_path'), '/').'/summary';
    }

    private function toScheduleGame(array $event, SeasonType $fallbackType): ?ScheduleGame
    {
        $competition = $event['competitions'][0] ?? null;

        if ($competition === null || ! isset($event['date'])) {
            return null;
        }

        $home = $this->competitor($competition, 'home');
        $away = $this->competitor($competition, 'away');

        if ($home === null || $away === null) {
            return null;
        }

        $statusName = (string) ($competition['status']['type']['name'] ?? '');

        return new ScheduleGame(
            sport: $this->sport,
            sourceEventId: (string) $event['id'],
            homeTeamName: (string) ($home['team']['displayName'] ?? ''),
            awayTeamName: (string) ($away['team']['displayName'] ?? ''),
            startsAt: CarbonImmutable::parse($event['date']),
            season: isset($event['season']['year']) ? (int) $event['season']['year'] : null,
            seasonType: $this->seasonType($event['season']['type'] ?? null, $fallbackType),
            week: isset($event['week']['number']) ? (int) $event['week']['number'] : null,
            status: $this->status($statusName),
            homeScore: $this->score($home),
            awayScore: $this->score($away),
            homeTeamExternalId: isset($home['team']['id']) ? (string) $home['team']['id'] : null,
            awayTeamExternalId: isset($away['team']['id']) ? (string) $away['team']['id'] : null,
            venueName: $competition['venue']['fullName'] ?? null,
            venueCity: $competition['venue']['address']['city'] ?? null,
            venueRegion: $competition['venue']['address']['state'] ?? null,
            // Absent rather than false when the feed does not say, so an
            // unknown roof is never mistaken for an open one.
            venueIndoor: isset($competition['venue']['indoor'])
                ? (bool) $competition['venue']['indoor']
                : null,
            neutralSite: (bool) ($competition['neutralSite'] ?? false),
            wentOvertime: ((int) ($competition['status']['period'] ?? 0)) > $this->regulationPeriods(),
        );
    }

    private function competitor(array $competition, string $homeAway): ?array
    {
        foreach ($competition['competitors'] ?? [] as $competitor) {
            if (($competitor['homeAway'] ?? null) === $homeAway) {
                return $competitor;
            }
        }

        return null;
    }

    private function score(array $competitor): ?int
    {
        $score = $competitor['score'] ?? null;

        return ($score === null || $score === '') ? null : (int) $score;
    }

    private function status(string $name): EventStatus
    {
        return match ($name) {
            'STATUS_FINAL', 'STATUS_FINAL_OVERTIME' => EventStatus::Final,
            'STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD' => EventStatus::InProgress,
            'STATUS_POSTPONED', 'STATUS_DELAYED', 'STATUS_SUSPENDED' => EventStatus::Postponed,
            'STATUS_CANCELED' => EventStatus::Canceled,
            default => EventStatus::Scheduled,
        };
    }

    private function seasonType(mixed $type, SeasonType $fallback): SeasonType
    {
        return match ((int) ($type ?? 0)) {
            1 => SeasonType::Preseason,
            2 => SeasonType::Regular,
            3 => SeasonType::Postseason,
            default => $fallback,
        };
    }

    private function regulationPeriods(): int
    {
        return match ($this->sport->config('group')) {
            'basketball' => $this->sport === Sport::Ncaab ? 2 : 4,
            'hockey' => 3,
            'baseball' => 9,
            default => 4,
        };
    }

    /**
     * Seasons are labelled by their starting year. A season whose months wrap
     * past New Year runs on into the following calendar year.
     */
    private function seasonWindow(int $season): array
    {
        $months = array_map('intval', (array) $this->sport->config('season_months'));

        if ($months === []) {
            return [CarbonImmutable::create($season, 1, 1), CarbonImmutable::create($season, 12, 31)];
        }

        $wraps = max($months) >= 8 && min($months) <= 6;

        return $wraps
            ? [CarbonImmutable::create($season, 8, 1), CarbonImmutable::create($season + 1, 7, 15)]
            : [CarbonImmutable::create($season, 2, 1), CarbonImmutable::create($season, 12, 31)];
    }

    private function url(): string
    {
        return self::BASE.'/'.trim((string) $this->sport->config('espn_path'), '/').'/scoreboard';
    }
}
