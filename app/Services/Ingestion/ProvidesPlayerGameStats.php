<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Data\PlayerGameStatLine;

/**
 * Implemented by sources that serve player lines a week at a time.
 *
 * The counterpart to ProvidesGameBoxScore, which answers per game and
 * returns team and player lines together. Both exist because the sources
 * genuinely differ: ESPN serves one game and knows everything about it,
 * while CFBD serves a week of player lines on one endpoint and a week of
 * team lines on another.
 *
 * Separate from ProvidesTeamGameStats rather than folded into it, because
 * a source can have one and not the other - which is exactly the case
 * this interface exists for. CFBD's team endpoint carries no player lines
 * at all, and that was recorded for a while as "CFBD has no player
 * stats", leaving ten thousand finished college games with none.
 */
interface ProvidesPlayerGameStats
{
    /**
     * Player lines for a season, optionally one week of it.
     *
     * Yields one line per player per stat category, so a quarterback who
     * also carried the ball appears once for passing and once for
     * rushing. Collapsing those would put a rushing yard and a passing
     * yard in the same field.
     *
     * @return iterable<int, PlayerGameStatLine>
     */
    public function playerGameStats(int $season, ?int $week = null): iterable;
}
