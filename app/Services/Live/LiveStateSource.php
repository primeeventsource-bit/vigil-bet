<?php

declare(strict_types=1);

namespace App\Services\Live;

use App\Data\LiveGameState;
use App\Enums\Sport;
use App\Services\Ingestion\SourceReliability;

/**
 * A source of in-play game state, behind the same kind of adapter the
 * pregame pipeline already uses: adding a feed is one class, never a change
 * to the probe itself.
 *
 * This interface exists to be measured before it is relied on. Phase 0 of
 * the live engine is an audit, and an adapter's job here is to report what
 * its feed actually publishes - including that it publishes no timestamp,
 * no clock, or no possession.
 */
interface LiveStateSource
{
    /** Attach the current job run so every HTTP call is attributable. */
    public function withJobRun(?int $jobRunId): static;

    /** Stable key, stored on every probe row this source writes. */
    public function key(): string;

    public function sport(): Sport;

    /** False when the sport is not covered or a key is missing. */
    public function isAvailable(): bool;

    public function reliability(): SourceReliability;

    public function caveats(): ?string;

    /**
     * Does this feed stamp its own readings? When it does not, we can
     * measure how far behind it is relative to another source but never how
     * far behind the event itself.
     */
    public function stampsItsOwnReadings(): bool;

    /**
     * Every game this source currently considers in progress.
     *
     * @return array<int, LiveGameState>
     */
    public function inPlay(): array;
}
