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
 * MLB StatsAPI (statsapi.mlb.com). Public, documented, and the same feed the
 * league fronts, including probable pitchers, which the MLB model needs.
 */
class MlbStatsApiSource implements SportDataSource
{
    use MakesHttpRequests;

    public function key(): string
    {
        return 'mlb_statsapi';
    }

    public function sport(): Sport
    {
        return Sport::Mlb;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function reliability(): SourceReliability
    {
        return SourceReliability::DocumentedPublic;
    }

    public function caveats(): ?string
    {
        return 'Unauthenticated public endpoint. Rate limit politely.';
    }

    public function schedule(int $season, SeasonType $seasonType = SeasonType::Regular): iterable
    {
        return $this->fetchGames([
            'sportId' => 1,
            'season' => $season,
            'gameType' => $this->gameType($seasonType),
            'hydrate' => 'team,linescore,probablePitcher',
        ], $season, $seasonType);
    }

    public function games(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return $this->fetchGames([
            'sportId' => 1,
            'startDate' => $from->toDateString(),
            'endDate' => $to->toDateString(),
            'hydrate' => 'team,linescore,probablePitcher',
        ], (int) $from->year, SeasonType::Regular);
    }

    private function fetchGames(array $query, int $season, SeasonType $seasonType): Generator
    {
        $payload = $this->getJson($this->url('schedule'), $query);

        foreach ($payload['dates'] ?? [] as $date) {
            foreach ($date['games'] ?? [] as $game) {
                $home = $game['teams']['home'] ?? [];
                $away = $game['teams']['away'] ?? [];

                if (! isset($home['team']['name'], $away['team']['name'], $game['gameDate'])) {
                    continue;
                }

                yield new ScheduleGame(
                    sport: Sport::Mlb,
                    sourceEventId: (string) $game['gamePk'],
                    homeTeamName: (string) $home['team']['name'],
                    awayTeamName: (string) $away['team']['name'],
                    startsAt: CarbonImmutable::parse($game['gameDate']),
                    season: (int) ($game['season'] ?? $season),
                    seasonType: $seasonType,
                    status: $this->status((string) ($game['status']['abstractGameState'] ?? ''), (string) ($game['status']['detailedState'] ?? '')),
                    homeScore: isset($home['score']) ? (int) $home['score'] : null,
                    awayScore: isset($away['score']) ? (int) $away['score'] : null,
                    homeTeamExternalId: isset($home['team']['id']) ? (string) $home['team']['id'] : null,
                    awayTeamExternalId: isset($away['team']['id']) ? (string) $away['team']['id'] : null,
                    venueName: $game['venue']['name'] ?? null,
                    wentOvertime: ((int) ($game['linescore']['currentInning'] ?? 9)) > 9,
                    periodScores: $this->innings($game['linescore'] ?? []),
                );
            }
        }
    }

    /** Probable starting pitchers for a date, keyed by MLB game id. */
    public function probablePitchers(CarbonImmutable $date): array
    {
        $payload = $this->getJson($this->url('schedule'), [
            'sportId' => 1,
            'date' => $date->toDateString(),
            'hydrate' => 'probablePitcher',
        ]);

        $out = [];

        foreach ($payload['dates'] ?? [] as $day) {
            foreach ($day['games'] ?? [] as $game) {
                foreach (['home', 'away'] as $sideKey) {
                    $pitcher = $game['teams'][$sideKey]['probablePitcher'] ?? null;

                    if ($pitcher === null) {
                        continue;
                    }

                    $out[(string) $game['gamePk']][$sideKey] = [
                        'id' => (string) ($pitcher['id'] ?? ''),
                        'name' => (string) ($pitcher['fullName'] ?? ''),
                    ];
                }
            }
        }

        return $out;
    }

    private function innings(array $linescore): ?array
    {
        $innings = $linescore['innings'] ?? null;

        if (! is_array($innings) || $innings === []) {
            return null;
        }

        return array_map(static fn (array $i) => [
            'num' => $i['num'] ?? null,
            'home' => $i['home']['runs'] ?? null,
            'away' => $i['away']['runs'] ?? null,
        ], $innings);
    }

    private function status(string $abstract, string $detailed): EventStatus
    {
        if (str_contains(mb_strtolower($detailed), 'postponed')) {
            return EventStatus::Postponed;
        }

        if (str_contains(mb_strtolower($detailed), 'cancel')) {
            return EventStatus::Canceled;
        }

        return match ($abstract) {
            'Final' => EventStatus::Final,
            'Live' => EventStatus::InProgress,
            default => EventStatus::Scheduled,
        };
    }

    private function gameType(SeasonType $type): string
    {
        return match ($type) {
            SeasonType::Preseason => 'S',
            SeasonType::Postseason => 'P',
            SeasonType::Regular => 'R',
        };
    }

    private function url(string $path): string
    {
        return rtrim((string) config('edge.sources.mlb_statsapi.base_url'), '/').'/'.ltrim($path, '/');
    }
}
