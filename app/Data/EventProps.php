<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Sport;

/**
 * Every player prop quote returned for one event in one pull.
 *
 * Unlike EventOdds this carries no team names or kickoff: props are only
 * ever pulled for an event we already have, by its Odds API id, so there is
 * nothing to resolve and nothing to create.
 */
final readonly class EventProps
{
    /** @param  array<int, PropQuote>  $quotes */
    public function __construct(
        public Sport $sport,
        /** The id asked for. Quotes are written against this game. */
        public string $oddsApiEventId,
        public array $quotes,
        /**
         * The id the payload came back carrying, when it carried one.
         *
         * It should always equal the one asked for - the id is in the URL.
         * If it ever does not, the quotes belong to a different game and
         * writing them here would quietly attach one game's prices to
         * another, so the caller checks rather than assumes.
         */
        public ?string $returnedEventId = null,
    ) {}

    public function isForRequestedEvent(): bool
    {
        return $this->returnedEventId === null || $this->returnedEventId === $this->oddsApiEventId;
    }

    public function quoteCount(): int
    {
        return count($this->quotes);
    }

    /** @return array<int, string> the market keys the book actually returned */
    public function marketKeys(): array
    {
        return array_values(array_unique(array_map(
            static fn (PropQuote $q) => $q->marketKey,
            $this->quotes,
        )));
    }
}
