<?php

declare(strict_types=1);

namespace App\Data;

use App\Services\Ingestion\ProvidesGameBoxScore;

/**
 * Everything one request can say about one finished game.
 *
 * Team lines and player lines together, because the sources that have
 * both return both in a single payload. Splitting them across two
 * interfaces would mean fetching the same response twice per game, and
 * at one request per game that is the difference between a backfill
 * that finishes and one that does not.
 *
 * @see ProvidesGameBoxScore
 */
final readonly class GameBoxScore
{
    /**
     * @param  array<int, TeamGameStatLine>  $teams
     * @param  array<int, PlayerGameStatLine>  $players
     */
    public function __construct(
        public string $sourceEventId,
        public array $teams = [],
        public array $players = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->teams === [] && $this->players === [];
    }
}
