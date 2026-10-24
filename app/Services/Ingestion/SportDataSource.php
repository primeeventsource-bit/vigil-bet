<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Data\ScheduleGame;
use App\Enums\SeasonType;
use App\Enums\Sport;
use Carbon\CarbonImmutable;

/**
 * Every sport plugs in behind this interface. Adding a sport is a config
 * entry plus one class that implements this - never a change to the
 * ingestion pipeline itself.
 */
interface SportDataSource
{
    /** Attach the current job run so every HTTP call is attributable. */
    public function withJobRun(?int $jobRunId): static;

    /** Stable key, stored on every row this source writes. */
    public function key(): string;

    public function sport(): Sport;

    /** False when a required API key is missing, so callers can skip cleanly. */
    public function isAvailable(): bool;

    /**
     * How much this source can be trusted, surfaced on the Jobs screen:
     * official, documented-public, or unofficial.
     */
    public function reliability(): SourceReliability;

    /** Known caveats, shown in the UI next to data from this source. */
    public function caveats(): ?string;

    /**
     * Schedule for a season, including results for games already played.
     *
     * @return iterable<int, ScheduleGame>
     */
    public function schedule(int $season, SeasonType $seasonType = SeasonType::Regular): iterable;

    /**
     * Games in a date window, used for day-to-day result syncing.
     *
     * @return iterable<int, ScheduleGame>
     */
    public function games(CarbonImmutable $from, CarbonImmutable $to): iterable;
}
