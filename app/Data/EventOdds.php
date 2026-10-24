<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Sport;
use Carbon\CarbonImmutable;

/**
 * All quotes returned for one event in one pull.
 */
final readonly class EventOdds
{
    /** @param  array<int, OddsQuote>  $quotes */
    public function __construct(
        public Sport $sport,
        public string $oddsApiEventId,
        public string $homeTeamName,
        public string $awayTeamName,
        public CarbonImmutable $commenceAt,
        public array $quotes,
    ) {}

    public function quoteCount(): int
    {
        return count($this->quotes);
    }
}
