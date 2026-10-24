<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Data\TeamGameStatLine;
use App\Services\Teams\TeamResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lands box scores against our own events and teams.
 *
 * The source speaks its own identifiers. This is the only place that
 * knows how to turn "Michigan" and game 401767704 into our team and
 * event rows, and it is deliberately the only place: a source that
 * resolved its own ids would need to know about aliases, and an alias is
 * a fact about our data rather than theirs.
 *
 * Unresolved rows are counted and logged, never guessed. A box score
 * attached to the wrong team is worse than a missing one - it does not
 * show up as a gap, it shows up as a team that played a game it did not
 * play, and every rating downstream believes it.
 */
class TeamGameStatsWriter
{
    /** Rows per upsert. Chosen to keep one statement well inside limits. */
    private const CHUNK = 200;

    /**
     * Lookups already done, keyed by what was asked.
     *
     * Three queries per line - the event, the team, and the event again
     * for the opponent - across thousands of lines in a season backfill.
     * Both sides of a game share the event, and a team appears on every
     * game it played, so almost all of that was the same question twice.
     *
     * @var array<string, int|null>
     */
    private array $events = [];

    /** @var array<string, int|null> */
    private array $resolved = [];

    /** @var array<int, array{home:int, away:int}|null> */
    private array $sides = [];

    public function __construct(private readonly TeamResolver $teams) {}

    /**
     * @param  iterable<int, TeamGameStatLine>  $lines
     * @return array{written:int, no_event:int, no_team:int, empty:int}
     */
    public function write(iterable $lines): array
    {
        $counts = ['written' => 0, 'no_event' => 0, 'no_team' => 0, 'empty' => 0];
        $batch = [];

        foreach ($lines as $line) {
            if (! $line->hasContent()) {
                $counts['empty']++;

                continue;
            }

            $row = $this->row($line, $counts);

            if ($row === null) {
                continue;
            }

            $batch[] = $row;

            if (count($batch) >= self::CHUNK) {
                $counts['written'] += $this->flush($batch);
                $batch = [];
            }
        }

        $counts['written'] += $this->flush($batch);

        return $counts;
    }

    /**
     * One row, or null with a reason counted.
     *
     * @param  array<string, int>  $counts
     * @return array<string, mixed>|null
     */
    private function row(TeamGameStatLine $line, array &$counts): ?array
    {
        $eventId = $this->eventId($line);

        if ($eventId === null) {
            $counts['no_event']++;

            return null;
        }

        $teamId = $this->teamId($line);

        if ($teamId === null) {
            $counts['no_team']++;

            Log::info('Team game stats: team not resolved', [
                'source' => $line->source,
                'sport' => $line->sport->value,
                'name' => $line->sourceTeamName,
                'source_team_id' => $line->sourceTeamId,
            ]);

            return null;
        }

        $opponentId = $this->opponentId($eventId, $teamId);

        if ($opponentId === null) {
            /*
             * The event is ours and the team is ours, but the team is
             * not in that event. That is a mismatch rather than a gap -
             * most likely an alias pointing at the wrong school - so the
             * row is dropped loudly rather than stored against whichever
             * side happened to be left.
             */
            $counts['no_team']++;

            Log::warning('Team game stats: resolved team is not in the event', [
                'event_id' => $eventId,
                'team_id' => $teamId,
                'name' => $line->sourceTeamName,
            ]);

            return null;
        }

        return [
            'event_id' => $eventId,
            'team_id' => $teamId,
            'opponent_team_id' => $opponentId,
            'is_home' => $line->isHome,
            'points' => $line->points,
            'points_allowed' => $line->pointsAllowed,
            'period_scores' => $line->periodScores === null ? null : json_encode($line->periodScores),
            'possessions' => $line->possessions,
            'pace' => $line->pace,
            'stats' => json_encode($line->stats),
            'source' => $line->source,
            'as_of' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Upsert on (event_id, team_id), which the table already enforces.
     *
     * Upsert rather than insert because a box score is corrected after
     * the game: a stat line pulled minutes after the whistle and the
     * same line the next morning are not always equal, and the later one
     * is the right one. This table is not append-only for that reason.
     *
     * @param  array<int, array<string, mixed>>  $batch
     */
    private function flush(array $batch): int
    {
        if ($batch === []) {
            return 0;
        }

        DB::table('team_game_stats')->upsert(
            $batch,
            ['event_id', 'team_id'],
            [
                'opponent_team_id', 'is_home', 'points', 'points_allowed',
                'period_scores', 'possessions', 'pace', 'stats',
                'source', 'as_of', 'updated_at',
            ],
        );

        return count($batch);
    }

    /** Our event id for the source's game, or null if we do not have it. */
    private function eventId(TeamGameStatLine $line): ?int
    {
        $key = $line->source.'|'.$line->sourceEventId;

        // array_key_exists, not isset: a miss is cached as null, and
        // isset would ask again for every line of a game we do not have.
        if (array_key_exists($key, $this->events)) {
            return $this->events[$key];
        }

        $id = DB::table('event_external_ids')
            ->where('source', $line->source)
            ->where('external_id', $line->sourceEventId)
            ->value('event_id');

        return $this->events[$key] = $id === null ? null : (int) $id;
    }

    /** The team id, by the source's id first and its name second. */
    private function teamId(TeamGameStatLine $line): ?int
    {
        $key = implode('|', [
            $line->sport->value,
            $line->source,
            $line->sourceTeamId ?? '',
            $line->sourceTeamName,
        ]);

        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $team = $this->teams->matches(
            $line->sport,
            $line->sourceTeamName,
            $line->source,
            $line->sourceTeamId,
        );

        return $this->resolved[$key] = $team?->id;
    }

    /**
     * The other side of the event, read from the event itself.
     *
     * Taken from our own row rather than from the source's other entry,
     * so that a row is only written when our view of who played agrees
     * with theirs. Returns null when the team is in neither slot, which
     * is the mismatch the caller refuses on.
     */
    private function opponentId(int $eventId, int $teamId): ?int
    {
        if (! array_key_exists($eventId, $this->sides)) {
            $event = DB::table('events')
                ->select('home_team_id', 'away_team_id')
                ->where('id', $eventId)
                ->first();

            $this->sides[$eventId] = $event === null ? null : [
                'home' => (int) $event->home_team_id,
                'away' => (int) $event->away_team_id,
            ];
        }

        $sides = $this->sides[$eventId];

        if ($sides === null) {
            return null;
        }

        return match ($teamId) {
            $sides['home'] => $sides['away'],
            $sides['away'] => $sides['home'],
            default => null,
        };
    }
}
