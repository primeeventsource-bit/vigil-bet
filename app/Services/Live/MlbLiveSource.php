<?php

declare(strict_types=1);

namespace App\Services\Live;

use App\Data\LiveGameState;
use App\Enums\EventStatus;
use App\Enums\Sport;
use App\Services\Ingestion\MakesHttpRequests;
use App\Services\Ingestion\SourceReliability;
use Carbon\CarbonImmutable;

/**
 * MLB's own StatsAPI, read live.
 *
 * The one source here that stamps its readings: the schedule carries the
 * time it was generated, so this is the only feed whose absolute staleness
 * can be measured rather than merely compared. That makes it the reference
 * the other adapters are judged against where a sport overlaps.
 *
 * Baseball has no clock. The period is the inning, and the half-inning is
 * carried as the situation, because an inning number alone does not say how
 * much baseball is left in it.
 */
class MlbLiveSource implements LiveStateSource
{
    use MakesHttpRequests;

    public function __construct(private readonly Sport $sport = Sport::Mlb) {}

    public function key(): string
    {
        return 'mlb_statsapi';
    }

    public function sport(): Sport
    {
        return $this->sport;
    }

    public function isAvailable(): bool
    {
        return $this->sport === Sport::Mlb;
    }

    public function reliability(): SourceReliability
    {
        return SourceReliability::DocumentedPublic;
    }

    public function caveats(): ?string
    {
        return 'No clock in baseball; the inning and half-inning stand in for one.';
    }

    public function stampsItsOwnReadings(): bool
    {
        return true;
    }

    /** @return array<int, LiveGameState> */
    public function inPlay(): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $payload = $this->getJson($this->url('schedule'), [
            'sportId' => 1,
            'date' => CarbonImmutable::now('America/New_York')->format('Y-m-d'),
            'hydrate' => 'linescore',
        ]);

        $reportedAt = $this->reportedAt($payload);
        $states = [];

        foreach ($payload['dates'] ?? [] as $date) {
            foreach ($date['games'] ?? [] as $game) {
                $state = $this->toState($game, $reportedAt);

                if ($state !== null && $state->isLive()) {
                    $states[] = $state;
                }
            }
        }

        return $states;
    }

    private function toState(array $game, ?CarbonImmutable $reportedAt): ?LiveGameState
    {
        $pk = $game['gamePk'] ?? null;

        if ($pk === null) {
            return null;
        }

        $line = (array) ($game['linescore'] ?? []);

        return new LiveGameState(
            externalRef: (string) $pk,
            status: $this->status((string) ($game['status']['abstractGameState'] ?? '')),
            homeScore: isset($line['teams']['home']['runs']) ? (int) $line['teams']['home']['runs'] : null,
            awayScore: isset($line['teams']['away']['runs']) ? (int) $line['teams']['away']['runs'] : null,
            period: isset($line['currentInning']) ? (int) $line['currentInning'] : null,
            clockSeconds: null,
            clockDisplay: null,
            // Which side is batting is the closest thing baseball has to
            // possession, and it matters as much.
            possession: match (strtolower((string) ($line['inningHalf'] ?? ''))) {
                'top' => 'away',
                'bottom' => 'home',
                default => null,
            },
            situation: isset($line['outs']) ? $line['outs'].' out' : null,
            sourceReportedAt: $reportedAt,
            homeName: $game['teams']['home']['team']['name'] ?? null,
            awayName: $game['teams']['away']['team']['name'] ?? null,
        );
    }

    private function reportedAt(array $payload): ?CarbonImmutable
    {
        foreach (['metaData.timeStamp', 'timeStamp'] as $path) {
            $value = data_get($payload, $path);

            if (is_string($value) && $value !== '') {
                // StatsAPI stamps as yyyymmdd_hhmmss in UTC.
                $parsed = CarbonImmutable::createFromFormat('Ymd_His', $value, 'UTC');

                if ($parsed !== false) {
                    return $parsed;
                }
            }
        }

        return null;
    }

    private function status(string $state): EventStatus
    {
        return match ($state) {
            'Live' => EventStatus::InProgress,
            'Final' => EventStatus::Final,
            default => EventStatus::Scheduled,
        };
    }

    private function url(string $path): string
    {
        return rtrim((string) config('edge.sources.mlb_statsapi.base_url'), '/').'/'.ltrim($path, '/');
    }
}
