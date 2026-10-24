<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Data\TeamGameStatLine;

/**
 * Implemented by sources that can supply box scores for finished games.
 *
 * Opt-in rather than part of SportDataSource, the same way
 * ProvidesHistoricalLines is: most sources can list a schedule and a
 * result, and only some can say what happened inside the game. A source
 * that cannot should not be made to declare a method that returns
 * nothing.
 *
 * Without this the engine rates teams on results alone. A team that wins
 * three games on turnovers and a team that wins three by four hundred
 * yards are the same team to an Elo rating, and the market knows they
 * are not.
 */
interface ProvidesTeamGameStats
{
    /**
     * Box scores for a season, optionally one week of it.
     *
     * Yields two rows per game, one per team. Week is accepted because
     * the sources that have weeks rate-limit hard enough that fetching a
     * whole season in one request is not offered, and because keeping
     * current means asking for the week just played rather than all of
     * them again.
     *
     * @return iterable<int, TeamGameStatLine>
     */
    public function teamGameStats(int $season, ?int $week = null): iterable;
}
