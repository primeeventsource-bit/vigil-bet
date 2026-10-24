<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Sport;

/**
 * A closing line recovered from a historical dataset rather than from our own
 * snapshots. Used to give backtests a real market benchmark and CLV for
 * seasons that happened before this app existed.
 *
 * Spreads are expressed from the home side, the way a book prints them:
 * -3.5 means the home team is laying 3.5.
 */
final readonly class HistoricalLine
{
    public function __construct(
        public Sport $sport,
        public string $sourceEventId,
        public ?int $homeMoneylineAmerican = null,
        public ?int $awayMoneylineAmerican = null,
        public ?float $homeSpread = null,
        public ?int $homeSpreadOddsAmerican = null,
        public ?int $awaySpreadOddsAmerican = null,
        public ?float $totalLine = null,
        public ?int $overOddsAmerican = null,
        public ?int $underOddsAmerican = null,
        public string $provenance = 'historical_dataset',
    ) {}

    public function hasAnyPrice(): bool
    {
        return $this->homeMoneylineAmerican !== null
            || $this->homeSpread !== null
            || $this->totalLine !== null;
    }
}
