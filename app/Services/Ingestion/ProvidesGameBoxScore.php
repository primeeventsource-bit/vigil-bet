<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Data\GameBoxScore;

/**
 * Implemented by sources that answer per game rather than per week.
 *
 * The counterpart to ProvidesTeamGameStats, which asks for a season and
 * gets every game in it. The two exist because the sources genuinely
 * differ: CFBD serves a week of college football box scores in one
 * request and has no player lines on that endpoint, while ESPN serves
 * one game at a time and returns team and player lines together.
 *
 * Pretending both were the same shape would mean one of them lying -
 * either ESPN fetching a whole season it cannot ask for, or CFBD making
 * a request per game it does not need.
 */
interface ProvidesGameBoxScore
{
    /**
     * The box score for one game, by the source's id for it.
     *
     * Returns an empty box score rather than throwing when the source
     * has nothing for the game: a postponed fixture and an abandoned one
     * are normal, and a backfill should walk past them.
     */
    public function gameBoxScore(string $sourceEventId): GameBoxScore;
}
