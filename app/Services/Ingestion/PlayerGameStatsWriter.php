<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Data\PlayerGameStatLine;
use App\Services\Teams\TeamResolver;
use Illuminate\Support\Facades\DB;

/**
 * Lands player lines against our own events.
 *
 * Deliberately more forgiving than TeamGameStatsWriter about the team.
 * A team box score with the wrong team attached is a lie about who
 * played, so that writer refuses it. A player line whose side could not
 * be resolved is still a true statement about what happened in the game
 * - the player, the game and the numbers are all known - so it is kept
 * with a null team rather than thrown away, and dropping it would lose
 * a whole roster over one unmatched name.
 *
 * The event is not negotiable. A line with no event belongs to no game
 * and there is nothing to do with it.
 */
class PlayerGameStatsWriter
{
    /** Rows per upsert. A football game yields a few hundred lines. */
    private const CHUNK = 400;

    public function __construct(private readonly TeamResolver $teams) {}

    /**
     * @param  iterable<int, PlayerGameStatLine>  $lines
     * @return array{written:int, no_event:int, no_team:int, empty:int}
     */
    public function write(iterable $lines): array
    {
        $counts = ['written' => 0, 'no_event' => 0, 'no_team' => 0, 'empty' => 0];
        $batch = [];
        $seen = [];

        foreach ($lines as $line) {
            if (! $line->hasContent()) {
                $counts['empty']++;

                continue;
            }

            $eventId = $this->eventId($line);

            if ($eventId === null) {
                $counts['no_event']++;

                continue;
            }

            /*
             * Deduplicated within the batch as well as by the unique
             * key. An upsert that names the same row twice in one
             * statement is an error in Postgres - "cannot affect row a
             * second time" - not a silent overwrite, so the batch has to
             * be clean before it is sent.
             */
            $key = $eventId.'|'.$line->source.'|'.$line->playerExternalId.'|'.$line->statGroup;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $teamId = $this->teamId($line);

            if ($teamId === null) {
                // Counted, not dropped: the line is still true.
                $counts['no_team']++;
            }

            $batch[] = [
                'sport' => $line->sport->value,
                'event_id' => $eventId,
                'team_id' => $teamId,
                'player_external_id' => $line->playerExternalId,
                'player_name' => $line->playerName,
                'position' => $line->position,
                'played' => $line->played,
                'stat_group' => $line->statGroup,
                'stats' => json_encode($line->stats),
                'source' => $line->source,
                'as_of' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($batch) >= self::CHUNK) {
                $counts['written'] += $this->flush($batch);
                $batch = [];
            }
        }

        $counts['written'] += $this->flush($batch);

        return $counts;
    }

    /** @param array<int, array<string, mixed>> $batch */
    private function flush(array $batch): int
    {
        if ($batch === []) {
            return 0;
        }

        DB::table('player_game_stats')->upsert(
            $batch,
            ['event_id', 'source', 'player_external_id', 'stat_group'],
            ['team_id', 'player_name', 'position', 'played', 'stats', 'sport', 'as_of', 'updated_at'],
        );

        return count($batch);
    }

    private function eventId(PlayerGameStatLine $line): ?int
    {
        $id = DB::table('event_external_ids')
            ->where('source', $line->source)
            ->where('external_id', $line->sourceEventId)
            ->value('event_id');

        return $id === null ? null : (int) $id;
    }

    private function teamId(PlayerGameStatLine $line): ?int
    {
        $team = $this->teams->matches(
            $line->sport,
            $line->sourceTeamName,
            $line->source,
            $line->sourceTeamId,
        );

        return $team?->id;
    }
}
