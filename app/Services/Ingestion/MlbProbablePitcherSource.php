<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\Sport;
use App\Enums\StarterRole;
use App\Models\Event;
use App\Models\EventExternalId;
use App\Models\ProbableStarter;
use Illuminate\Support\Carbon;

/**
 * Probable starting pitchers, from MLB's own schedule feed.
 *
 * The one starter this app will claim to know. MLB publishes probable
 * pitchers days ahead and they are the single biggest pre-game input in
 * the sport; the NHL publishes nothing equivalent for starting goalies, so
 * `probable_starters` carries pitchers and leaves the goalie role empty
 * rather than scrape a guess off a beat reporter. An empty column is a
 * statement that we do not know. A guessed one is not.
 *
 * Append-only, and a repeat pull of an unchanged pairing writes a new row.
 * A pitcher named four days out and still named on the morning of the game
 * are two different facts, and the second is worth much more.
 *
 * Handedness comes from a second, batched request. It is on the player
 * rather than the schedule, and left-handedness against a lineup is most of
 * why knowing the starter matters at all, so it is worth the extra call -
 * one for every pitcher on the slate, not one each.
 */
class MlbProbablePitcherSource
{
    use MakesHttpRequests;

    private const SCHEDULE = 'https://statsapi.mlb.com/api/v1/schedule';

    private const PEOPLE = 'https://statsapi.mlb.com/api/v1/people';

    public function key(): string
    {
        return 'mlb_statsapi';
    }

    /**
     * Pull probable pitchers for the next few days.
     *
     * @return array{starters: int, games: int, unmatched_games: int, without_pitcher: int, already_started: int}
     */
    public function sync(int $days = 5): array
    {
        $from = Carbon::now();
        $to = Carbon::now()->addDays($days);

        $payload = $this->getJson(self::SCHEDULE, [
            'sportId' => 1,
            'startDate' => $from->toDateString(),
            'endDate' => $to->toDateString(),
            'hydrate' => 'probablePitcher,team',
        ]);

        $rows = [];
        $games = 0;
        $unmatched = 0;
        $withoutPitcher = 0;
        $alreadyStarted = 0;

        foreach ((array) ($payload['dates'] ?? []) as $date) {
            foreach ((array) ($date['games'] ?? []) as $game) {
                $event = $this->resolveEvent($game);

                if ($event === null) {
                    $unmatched++;

                    continue;
                }

                // The window opens today, so it carries this morning's
                // games as well as tomorrow's. A starter written for one
                // of those would be stamped after its own kickoff, which
                // makes it a box-score fact sitting in a pre-game table -
                // exactly the shape of thing a feature would later read as
                // though it had been known in advance.
                if ($event->starts_at->lessThanOrEqualTo(now())) {
                    $alreadyStarted++;

                    continue;
                }

                $games++;

                foreach (['home', 'away'] as $side) {
                    $pitcher = $game['teams'][$side]['probablePitcher'] ?? null;

                    // Not yet announced. Normal, days out, and recorded as
                    // nothing rather than as a blank starter.
                    if (! is_array($pitcher) || ! is_string($pitcher['fullName'] ?? null)) {
                        $withoutPitcher++;

                        continue;
                    }

                    $teamId = $side === 'home' ? $event->home_team_id : $event->away_team_id;

                    if ($teamId === null) {
                        continue;
                    }

                    $rows[] = [
                        'event_id' => $event->id,
                        'team_id' => $teamId,
                        'player_external_id' => isset($pitcher['id']) ? (string) $pitcher['id'] : null,
                        'player_name' => mb_substr($pitcher['fullName'], 0, 255),
                    ];
                }
            }
        }

        $hands = $this->handedness(array_filter(array_column($rows, 'player_external_id')));
        $written = 0;

        foreach ($rows as $row) {
            ProbableStarter::create([
                'event_id' => $row['event_id'],
                'team_id' => $row['team_id'],
                'role' => StarterRole::Pitcher->value,
                'player_external_id' => $row['player_external_id'],
                'player_name' => $row['player_name'],
                'handedness' => $hands[$row['player_external_id']] ?? null,
                // MLB calls these probable and does not say when one becomes
                // official, so nothing here claims to be confirmed.
                'is_confirmed' => false,
                'source' => $this->key(),
                'reported_at' => now(),
                'created_at' => now(),
            ]);

            $written++;
        }

        return [
            'starters' => $written,
            'games' => $games,
            'unmatched_games' => $unmatched,
            'without_pitcher' => $withoutPitcher,
            'already_started' => $alreadyStarted,
        ];
    }

    /**
     * Left or right, for every pitcher on the slate in one request.
     *
     * @param  array<int, string>  $ids
     * @return array<string, string>
     */
    private function handedness(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $hands = [];

        // Chunked so a busy slate cannot build a URL long enough to be
        // rejected.
        foreach (array_chunk($ids, 50) as $chunk) {
            $payload = $this->getJson(self::PEOPLE, ['personIds' => implode(',', $chunk)]);

            foreach ((array) ($payload['people'] ?? []) as $person) {
                $code = $person['pitchHand']['code'] ?? null;

                if (isset($person['id']) && is_string($code)) {
                    $hands[(string) $person['id']] = mb_substr($code, 0, 2);
                }
            }
        }

        return $hands;
    }

    /**
     * Our event for an MLB game, by the gamePk the schedule adapter already
     * stores. Matched on the id rather than on team names and a date,
     * because a doubleheader is two games between the same teams on the
     * same day and a name match would collapse them.
     *
     * @param  array<string, mixed>  $game
     */
    private function resolveEvent(array $game): ?Event
    {
        if (! isset($game['gamePk'])) {
            return null;
        }

        $eventId = EventExternalId::query()
            ->where('source', $this->key())
            ->where('external_id', (string) $game['gamePk'])
            ->where('sport', Sport::Mlb->value)
            ->value('event_id');

        return $eventId ? Event::find($eventId) : null;
    }
}
