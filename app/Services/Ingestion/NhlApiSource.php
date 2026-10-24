<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Data\ScheduleGame;
use App\Enums\EventStatus;
use App\Enums\SeasonType;
use App\Enums\Sport;
use Carbon\CarbonImmutable;
use Generator;

/**
 * NHL public API (api-web.nhle.com). Undocumented but stable and widely
 * used; the weekly schedule endpoint walks a date range a week at a time.
 */
class NhlApiSource implements SportDataSource
{
    use MakesHttpRequests;

    public function key(): string
    {
        return 'nhl';
    }

    public function sport(): Sport
    {
        return Sport::Nhl;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function reliability(): SourceReliability
    {
        return SourceReliability::Unofficial;
    }

    public function caveats(): ?string
    {
        return 'Public but undocumented NHL endpoint. Shape can change without notice.';
    }

    public function schedule(int $season, SeasonType $seasonType = SeasonType::Regular): iterable
    {
        // NHL seasons span two calendar years: 2024 means the 2024-25 season.
        $from = CarbonImmutable::create($season, 9, 1);
        $to = CarbonImmutable::create($season + 1, 7, 1);

        return $this->games($from, $to, $season, $seasonType);
    }

    public function games(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $season = null,
        SeasonType $seasonType = SeasonType::Regular,
    ): Generator {
        $cursor = $from->startOfDay();

        while ($cursor->lessThanOrEqualTo($to)) {
            $payload = $this->getJson($this->url('schedule/'.$cursor->toDateString()));

            foreach ($payload['gameWeek'] ?? [] as $day) {
                foreach ($day['games'] ?? [] as $game) {
                    $parsed = $this->toScheduleGame($game, $season, $seasonType);

                    if ($parsed !== null) {
                        yield $parsed;
                    }
                }
            }

            // The feed tells us where the next week starts, but a backfill
            // cannot trust that blindly: a repeated or backwards date would
            // spin this loop forever, hanging a worker and burning the API.
            // Only accept a date that actually moves forward.
            $next = isset($payload['nextStartDate'])
                ? CarbonImmutable::parse($payload['nextStartDate'])
                : null;

            $cursor = ($next !== null && $next->greaterThan($cursor))
                ? $next
                : $cursor->addWeek();
        }
    }

    private function toScheduleGame(array $game, ?int $season, SeasonType $seasonType): ?ScheduleGame
    {
        $home = $game['homeTeam'] ?? [];
        $away = $game['awayTeam'] ?? [];

        $homeName = $this->teamName($home);
        $awayName = $this->teamName($away);

        if ($homeName === null || $awayName === null || ! isset($game['startTimeUTC'])) {
            return null;
        }

        $gameType = (int) ($game['gameType'] ?? 2);

        return new ScheduleGame(
            sport: Sport::Nhl,
            sourceEventId: (string) $game['id'],
            homeTeamName: $homeName,
            awayTeamName: $awayName,
            startsAt: CarbonImmutable::parse($game['startTimeUTC']),
            season: $season ?? (isset($game['season']) ? (int) mb_substr((string) $game['season'], 0, 4) : null),
            seasonType: match ($gameType) {
                1 => SeasonType::Preseason,
                3 => SeasonType::Postseason,
                default => $seasonType,
            },
            status: $this->status((string) ($game['gameState'] ?? '')),
            homeScore: isset($home['score']) ? (int) $home['score'] : null,
            awayScore: isset($away['score']) ? (int) $away['score'] : null,
            homeTeamExternalId: isset($home['id']) ? (string) $home['id'] : null,
            awayTeamExternalId: isset($away['id']) ? (string) $away['id'] : null,
            venueName: $this->localized($game['venue'] ?? null),
            neutralSite: (bool) ($game['neutralSite'] ?? false),
            wentOvertime: in_array($game['gameOutcome']['lastPeriodType'] ?? '', ['OT', 'SO'], true),
        );
    }

    private function teamName(array $team): ?string
    {
        $place = $this->localized($team['placeName'] ?? null);
        $common = $this->localized($team['commonName'] ?? null);

        if ($place !== null && $common !== null) {
            return trim($place.' '.$common);
        }

        return $common ?? $place ?? ($team['abbrev'] ?? null);
    }

    private function localized(mixed $value): ?string
    {
        if (is_array($value)) {
            return $value['default'] ?? null;
        }

        return is_string($value) ? $value : null;
    }

    private function status(string $state): EventStatus
    {
        return match ($state) {
            'OFF', 'FINAL' => EventStatus::Final,
            'LIVE', 'CRIT' => EventStatus::InProgress,
            'PPD' => EventStatus::Postponed,
            'CNCL' => EventStatus::Canceled,
            default => EventStatus::Scheduled,
        };
    }

    private function url(string $path): string
    {
        return rtrim((string) config('edge.sources.nhl.base_url'), '/').'/'.ltrim($path, '/');
    }
}
