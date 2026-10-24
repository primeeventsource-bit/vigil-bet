<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * The state of a sport's board as it stood at one past moment.
 *
 * The historical endpoint answers with the snapshot at or before the
 * timestamp asked for, and says which one it gave. That `timestamp` is the
 * one the rows are stamped with - not the one requested - because a quote
 * is a fact about when it was quoted, and the two differ whenever the feed
 * had no reading at the exact second asked for.
 */
final readonly class HistoricalOddsSnapshot
{
    /** @param  array<int, EventOdds>  $events */
    public function __construct(
        public CarbonImmutable $requestedAt,
        public ?CarbonImmutable $timestamp,
        public array $events,
    ) {}

    public function quoteCount(): int
    {
        return array_sum(array_map(static fn (EventOdds $e) => $e->quoteCount(), $this->events));
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }
}
