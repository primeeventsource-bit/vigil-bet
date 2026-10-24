<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\EventStatus;
use App\Services\Teams\NameNormalizer;
use Carbon\CarbonImmutable;

/**
 * One source's view of a game in progress, at one moment.
 *
 * Every field a source does not publish stays null. Nothing here is inferred
 * or carried forward from a previous reading: a missing clock has to look
 * missing, because the whole point of the feed audit is to find out what is
 * actually available before a model is built on the assumption that it is.
 */
final readonly class LiveGameState
{
    public function __construct(
        /** The game's id at this source. Ids differ between sources. */
        public string $externalRef,
        public EventStatus $status,
        public ?int $homeScore = null,
        public ?int $awayScore = null,
        public ?int $period = null,
        /** Seconds left in the period, where the sport has a clock at all. */
        public ?int $clockSeconds = null,
        public ?string $clockDisplay = null,
        /** Which side has the ball, where the source says. */
        public ?string $possession = null,
        public ?string $situation = null,
        /**
         * The source's own timestamp for this reading. Null where the source
         * publishes none, which makes its absolute staleness unmeasurable -
         * a finding in itself rather than a gap to paper over.
         */
        public ?CarbonImmutable $sourceReportedAt = null,
        public ?string $homeName = null,
        public ?string $awayName = null,
    ) {}

    /**
     * What has to change for a win probability to move discontinuously.
     *
     * The clock is deliberately not in here. It advances on every reading,
     * so folding it in would mark every poll as a change and make the
     * measurement that matters - how long a source takes to report a score
     * - impossible to extract.
     */
    public function materialDigest(): string
    {
        return substr(hash('sha256', implode('|', [
            $this->status->value,
            $this->homeScore ?? '-',
            $this->awayScore ?? '-',
            $this->period ?? '-',
            $this->possession ?? '-',
        ])), 0, 16);
    }

    /**
     * A key that identifies the same game across sources, which raw ids
     * cannot do: every feed numbers games its own way.
     */
    public function gameKey(): ?string
    {
        if ($this->homeName === null || $this->awayName === null) {
            return null;
        }

        return NameNormalizer::slug($this->awayName).'-at-'.NameNormalizer::slug($this->homeName);
    }

    public function isLive(): bool
    {
        return $this->status === EventStatus::InProgress;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'home_score' => $this->homeScore,
            'away_score' => $this->awayScore,
            'period' => $this->period,
            'clock_seconds' => $this->clockSeconds,
            'clock' => $this->clockDisplay,
            'possession' => $this->possession,
            'situation' => $this->situation,
            'home' => $this->homeName,
            'away' => $this->awayName,
        ];
    }
}
