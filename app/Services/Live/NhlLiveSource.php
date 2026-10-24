<?php

declare(strict_types=1);

namespace App\Services\Live;

use App\Data\LiveGameState;
use App\Enums\EventStatus;
use App\Enums\Sport;
use App\Services\Ingestion\MakesHttpRequests;
use App\Services\Ingestion\SourceReliability;

/**
 * The NHL's own score feed, which answers for every game at once.
 *
 * It exists here as a second opinion on the same games ESPN reports. Two
 * feeds on one game is the only way to measure either of them without a
 * licensed reference: whichever reports a goal first sets the clock, and
 * the gap to the other is that feed's lag relative to it.
 *
 * It publishes seconds remaining in the period directly, which ESPN also
 * does, so the two are comparable without parsing a display string.
 */
class NhlLiveSource implements LiveStateSource
{
    use MakesHttpRequests;

    public function __construct(private readonly Sport $sport = Sport::Nhl) {}

    public function key(): string
    {
        return 'nhl_api';
    }

    public function sport(): Sport
    {
        return $this->sport;
    }

    public function isAvailable(): bool
    {
        return $this->sport === Sport::Nhl;
    }

    public function reliability(): SourceReliability
    {
        return SourceReliability::Unofficial;
    }

    public function caveats(): ?string
    {
        return 'Public but undocumented NHL endpoint. No reading timestamp.';
    }

    public function stampsItsOwnReadings(): bool
    {
        return false;
    }

    /** @return array<int, LiveGameState> */
    public function inPlay(): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $payload = $this->getJson($this->url('score/now'));
        $states = [];

        foreach ($payload['games'] ?? [] as $game) {
            $state = $this->toState($game);

            if ($state !== null && $state->isLive()) {
                $states[] = $state;
            }
        }

        return $states;
    }

    private function toState(array $game): ?LiveGameState
    {
        $id = $game['id'] ?? null;

        if ($id === null) {
            return null;
        }

        $clock = (array) ($game['clock'] ?? []);

        return new LiveGameState(
            externalRef: (string) $id,
            status: $this->status((string) ($game['gameState'] ?? '')),
            homeScore: isset($game['homeTeam']['score']) ? (int) $game['homeTeam']['score'] : null,
            awayScore: isset($game['awayTeam']['score']) ? (int) $game['awayTeam']['score'] : null,
            period: isset($game['period']) ? (int) $game['period'] : null,
            clockSeconds: isset($clock['secondsRemaining']) ? (int) $clock['secondsRemaining'] : null,
            clockDisplay: isset($clock['timeRemaining']) ? (string) $clock['timeRemaining'] : null,
            // Hockey has no possession in the sense a feed can report.
            possession: null,
            situation: ($clock['inIntermission'] ?? false) ? 'intermission' : null,
            sourceReportedAt: null,
            homeName: $this->teamName($game['homeTeam'] ?? []),
            awayName: $this->teamName($game['awayTeam'] ?? []),
        );
    }

    /** Names arrive localized and sometimes split into place and nickname. */
    private function teamName(array $team): ?string
    {
        foreach (['name', 'placeName', 'commonName'] as $field) {
            $value = $team[$field]['default'] ?? null;

            if (is_string($value) && $value !== '') {
                return $field === 'placeName' && isset($team['commonName']['default'])
                    ? $value.' '.$team['commonName']['default']
                    : $value;
            }
        }

        return isset($team['abbrev']) ? (string) $team['abbrev'] : null;
    }

    private function status(string $state): EventStatus
    {
        return match ($state) {
            'LIVE', 'CRIT' => EventStatus::InProgress,
            'FINAL', 'OFF' => EventStatus::Final,
            default => EventStatus::Scheduled,
        };
    }

    private function url(string $path): string
    {
        return rtrim((string) config('edge.sources.nhl.base_url'), '/').'/'.ltrim($path, '/');
    }
}
