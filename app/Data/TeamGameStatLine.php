<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Sport;

/**
 * One team's box score from one finished game.
 *
 * The thing the engine has never had. Every model in the app rates teams
 * on results alone - who won, by how much - so a team that wins three
 * games on turnovers and a team that wins three by four hundred yards
 * look identical to it. These are the rows that let those come apart.
 *
 * `stats` is kept as the source's own category names rather than mapped
 * into columns. Sports disagree about what a box score even contains,
 * and a schema that tried to hold all of them would be mostly null; the
 * feature builder reads the handful of categories it understands and
 * ignores the rest, which also means a new category arriving upstream
 * costs nothing.
 *
 * `sourceEventId` and `sourceTeamName` are the source's identifiers, not
 * ours. Resolving them is the writer's job, because it is the writer
 * that can learn an alias when a name does not match.
 */
final readonly class TeamGameStatLine
{
    /**
     * @param  array<string, string|int|float>  $stats
     * @param  array<int, int>|null  $periodScores
     */
    public function __construct(
        public Sport $sport,
        public string $sourceEventId,
        public string $sourceTeamName,
        public ?string $sourceTeamId,
        public bool $isHome,
        public ?int $points,
        public ?int $pointsAllowed,
        public array $stats = [],
        public ?array $periodScores = null,
        public ?float $possessions = null,
        public ?float $pace = null,
        public string $source = 'unknown',
    ) {}

    /**
     * Whether this row says anything worth storing.
     *
     * A box score with no score and no categories is a row the source
     * returned because the game exists, not because it has been played.
     */
    public function hasContent(): bool
    {
        return $this->points !== null || $this->stats !== [];
    }
}
