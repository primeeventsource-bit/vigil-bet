<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Data\ScheduleGame;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Services\Teams\TeamResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Attaches a second source's event ids to events we already have.
 *
 * NFL events came from nflverse and carry ids like `2026_07_NE_CHI`.
 * ESPN is the only source with NFL box scores, and it answers by its own
 * numeric id, so without this there is nothing for a box score to join
 * to - which is exactly where the NFL stood: 830 finished games and zero
 * ESPN ids.
 *
 * Link only. EventSynchronizer would do the matching and also create any
 * game it could not find, which from a second source means a duplicate
 * row for every fixture either source names differently. Here a game we
 * cannot match is counted and skipped, because the purpose is to label
 * what exists rather than to discover anything.
 *
 * The matching follows EventSynchronizer::findEvent deliberately: a
 * twelve-hour window, which absorbs a moved kickoff but not the next
 * meeting of the same two teams, and skipping events this source has
 * already claimed under another id, which is what keeps the halves of a
 * doubleheader apart.
 */
class ExternalIdLinker
{
    public function __construct(private readonly TeamResolver $teams) {}

    /**
     * @param  iterable<int, ScheduleGame>  $games
     * @return array{linked:int, already:int, no_event:int, no_team:int}
     */
    public function link(iterable $games, string $source): array
    {
        $counts = ['linked' => 0, 'already' => 0, 'no_event' => 0, 'no_team' => 0];

        foreach ($games as $game) {
            $home = $this->teams->matches($game->sport, $game->homeTeamName, $source, $game->homeTeamExternalId);
            $away = $this->teams->matches($game->sport, $game->awayTeamName, $source, $game->awayTeamExternalId);

            if ($home === null || $away === null) {
                $counts['no_team']++;

                continue;
            }

            if ($this->alreadyLinked($game, $source)) {
                $counts['already']++;

                continue;
            }

            $event = $this->findEvent($game, $source, $home->id, $away->id);

            if ($event === null) {
                $counts['no_event']++;

                continue;
            }

            DB::table('event_external_ids')->insert([
                'event_id' => $event->id,
                'sport' => $game->sport->value,
                'source' => $source,
                'external_id' => $game->sourceEventId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $counts['linked']++;
        }

        if ($counts['no_team'] > 0 || $counts['no_event'] > 0) {
            Log::info('External id linking finished with gaps', $counts + ['source' => $source]);
        }

        return $counts;
    }

    private function alreadyLinked(ScheduleGame $game, string $source): bool
    {
        return DB::table('event_external_ids')
            ->where('source', $source)
            ->where('external_id', $game->sourceEventId)
            ->where('sport', $game->sport->value)
            ->exists();
    }

    /**
     * Our event for this game, or null.
     *
     * Matched on the natural key, since by definition this source has no
     * id on file yet. The two guards are the ones EventSynchronizer
     * uses, for the same reasons.
     */
    private function findEvent(ScheduleGame $game, string $source, int $homeId, int $awayId): ?Event
    {
        return Event::query()
            ->where('sport', $game->sport->value)
            ->where('home_team_id', $homeId)
            ->where('away_team_id', $awayId)
            ->whereBetween('starts_at', [
                $game->startsAt->subHours(12),
                $game->startsAt->addHours(12),
            ])
            ->where('status', '!=', EventStatus::Canceled->value)
            ->whereNotExists(function ($query) use ($source): void {
                $query->selectRaw('1')
                    ->from('event_external_ids')
                    ->whereColumn('event_external_ids.event_id', 'events.id')
                    ->where('event_external_ids.source', $source);
            })
            ->first();
    }
}
