<?php

declare(strict_types=1);

namespace App\Services\Live;

use App\Data\LiveGameState;
use App\Enums\EventStatus;
use App\Enums\Sport;
use App\Services\Ingestion\MakesHttpRequests;
use App\Services\Ingestion\SourceReliability;

/**
 * ESPN's scoreboard, read for in-play state rather than results.
 *
 * It is the only free source that answers for every sport here, and for
 * football it carries the one thing a live model cannot work without:
 * possession, down and distance.
 *
 * What it does not carry is a timestamp of its own. Every reading arrives
 * with no statement of when it was true, so this source can be compared
 * against another feed but never against the event itself. That is recorded
 * rather than worked around.
 */
class EspnLiveSource implements LiveStateSource
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
        return 'Undocumented ESPN scoreboard. No reading timestamp, so absolute staleness cannot be measured.';
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

        $payload = $this->getJson($this->url(), ['limit' => 500]);
        $states = [];

        foreach ($payload['events'] ?? [] as $event) {
            $competition = $event['competitions'][0] ?? null;

            if ($competition === null) {
                continue;
            }

            $state = $this->toState((string) ($event['id'] ?? ''), $competition);

            if ($state !== null && $state->isLive()) {
                $states[] = $state;
            }
        }

        return $states;
    }

    private function toState(string $id, array $competition): ?LiveGameState
    {
        if ($id === '') {
            return null;
        }

        $home = $this->competitor($competition, 'home');
        $away = $this->competitor($competition, 'away');
        $status = (array) ($competition['status'] ?? []);
        $situation = (array) ($competition['situation'] ?? []);

        return new LiveGameState(
            externalRef: $id,
            status: $this->status((string) ($status['type']['name'] ?? '')),
            homeScore: $this->score($home),
            awayScore: $this->score($away),
            period: isset($status['period']) ? (int) $status['period'] : null,
            // ESPN publishes the clock as seconds remaining in the period,
            // and as a display string. Baseball has neither.
            clockSeconds: isset($status['clock']) ? (int) round((float) $status['clock']) : null,
            clockDisplay: isset($status['displayClock']) ? (string) $status['displayClock'] : null,
            possession: $this->possession($competition, $situation),
            situation: isset($situation['downDistanceText']) ? (string) $situation['downDistanceText'] : null,
            // Deliberately null: see the class note.
            sourceReportedAt: null,
            homeName: $home['team']['displayName'] ?? null,
            awayName: $away['team']['displayName'] ?? null,
        );
    }

    /** Home or away, resolved from the team id the source gives possession to. */
    private function possession(array $competition, array $situation): ?string
    {
        $holder = $situation['possession'] ?? null;

        if ($holder === null) {
            return null;
        }

        foreach ($competition['competitors'] ?? [] as $competitor) {
            if ((string) ($competitor['team']['id'] ?? '') === (string) $holder) {
                return (string) ($competitor['homeAway'] ?? '');
            }
        }

        return null;
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

    private function score(?array $competitor): ?int
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

    private function url(): string
    {
        return self::BASE.'/'.trim((string) $this->sport->config('espn_path'), '/').'/scoreboard';
    }
}
