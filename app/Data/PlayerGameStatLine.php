<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Sport;

/**
 * One player's line from one game, as the source hands it over.
 *
 * `stats` keeps the source's own labels - "PTS", "REB", "C/ATT" - rather
 * than a normalised set. A basketball line and a football passing line
 * share nothing, and flattening both into one vocabulary would mean
 * choosing a winner between two sports' conventions and losing
 * information in whichever lost. The feature builder reads the keys it
 * understands for the sport in front of it.
 *
 * Values stay as the source printed them. "7-14" and "31:04" are not
 * numbers, and casting them turns the first into 7 and the second into
 * 31.
 */
final readonly class PlayerGameStatLine
{
    /** @param array<string, string|int|float> $stats */
    public function __construct(
        public Sport $sport,
        public string $sourceEventId,
        public string $playerExternalId,
        public string $playerName,
        public ?string $sourceTeamName = null,
        public ?string $sourceTeamId = null,
        public ?string $position = null,
        public bool $played = true,
        public string $statGroup = 'all',
        public array $stats = [],
        public string $source = 'unknown',
    ) {}

    /**
     * Whether this line says anything.
     *
     * A dressed inactive has no stats and is still worth recording -
     * "did not play" is a fact about the game - so an empty stat set is
     * only meaningless when the source also claims they played.
     */
    public function hasContent(): bool
    {
        return $this->stats !== [] || ! $this->played;
    }
}
