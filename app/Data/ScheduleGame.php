<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\EventStatus;
use App\Enums\SeasonType;
use App\Enums\Sport;
use Carbon\CarbonImmutable;

/**
 * A scheduled or completed game as reported by a sport data source. Scores
 * are null until the source reports them.
 */
final readonly class ScheduleGame
{
    public function __construct(
        public Sport $sport,
        public string $sourceEventId,
        public string $homeTeamName,
        public string $awayTeamName,
        public CarbonImmutable $startsAt,
        public ?int $season = null,
        public SeasonType $seasonType = SeasonType::Regular,
        public ?int $week = null,
        public EventStatus $status = EventStatus::Scheduled,
        public ?int $homeScore = null,
        public ?int $awayScore = null,
        public ?string $homeTeamExternalId = null,
        public ?string $awayTeamExternalId = null,
        public ?string $venueName = null,
        /*
         * Where the venue is, and whether it is covered. Carried on the
         * schedule because this is the only place the sources publish it,
         * and because weather is unusable without somewhere to point: a
         * venue row with a name and no coordinates cannot be forecast for.
         */
        public ?string $venueCity = null,
        public ?string $venueRegion = null,
        public ?bool $venueIndoor = null,
        public bool $neutralSite = false,
        public bool $wentOvertime = false,
        public ?array $periodScores = null,
        /** Schedule facts known before kickoff. */
        public ?int $homeRestDays = null,
        public ?int $awayRestDays = null,
        public ?string $roof = null,
        public ?string $surface = null,
        public bool $divisional = false,
    ) {}

    public function isFinal(): bool
    {
        return $this->status === EventStatus::Final;
    }
}
