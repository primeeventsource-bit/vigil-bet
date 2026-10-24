<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Data\ScheduleGame;
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
class EspnSource implements SportDataSource
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
