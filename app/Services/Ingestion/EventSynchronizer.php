<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Data\ScheduleGame;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventExternalId;
use App\Models\Venue;
use App\Services\Teams\TeamResolver;
use Illuminate\Support\Facades\DB;

/**
 * Writes schedule and result rows into events.
 *
 * Events are the one place a row is updated rather than appended, because a
 * game genuinely changes state: scheduled, then live, then final. Odds,
 * predictions and bets stay append-only around it.
 */
class EventSynchronizer
{
    /**
     * Venue name to id.
     *
     * Null until first needed, so a run that never sees a venue name
     * never reads the table. @var array<string, int>|null
     */
    private ?array $venueIds = null;

    public function __construct(private readonly TeamResolver $teams) {}

    /**
     * @param  iterable<int, ScheduleGame>  $games
     * @return array{seen:int, created:int, updated:int, unresolved:int}
     */
    public function sync(iterable $games, string $source): array
    {
        $stats = ['seen' => 0, 'created' => 0, 'updated' => 0, 'unresolved' => 0];

        foreach ($games as $game) {
            $stats['seen']++;

            $home = $this->teams->resolve(
                $game->sport,
                $game->homeTeamName,
                $source,
                $game->homeTeamExternalId,
                ['source_event_id' => $game->sourceEventId, 'role' => 'home'],
            );

            $away = $this->teams->resolve(
                $game->sport,
                $game->awayTeamName,
                $source,
                $game->awayTeamExternalId,
                ['source_event_id' => $game->sourceEventId, 'role' => 'away'],
            );

            if ($home === null || $away === null) {
                $stats['unresolved']++;

                continue;
            }

            DB::transaction(function () use ($game, $source, $home, $away, &$stats): void {
                ['event' => $event, 'claimed' => $claimed] = $this->findEvent($game, $source, $home->id, $away->id);

                $attributes = [
                    'sport' => $game->sport->value,
                    'home_team_id' => $home->id,
                    'away_team_id' => $away->id,
                    'starts_at' => $game->startsAt,
                    'season' => $game->season,
                    'season_type' => $game->seasonType->value,
                    'week' => $game->week,
                    'status' => $game->status->value,
                    'neutral_site' => $game->neutralSite,
                    'went_overtime' => $game->wentOvertime,
                    'last_result_sync_at' => now(),
                    'home_rest_days' => $game->homeRestDays,
                    'away_rest_days' => $game->awayRestDays,
                    'roof' => $game->roof,
                    'surface' => $game->surface,
                    'divisional' => $game->divisional,
                ];

                if ($game->isFinal()) {
                    $attributes['final_home_score'] = $game->homeScore;
                    $attributes['final_away_score'] = $game->awayScore;
                    $attributes['period_scores'] = $game->periodScores;
                    $attributes['completed_at'] = $game->startsAt;
                }

                if ($venueId = $this->venueId($game)) {
                    $attributes['venue_id'] = $venueId;
                }

                if ($event === null) {
                    $event = Event::create($attributes);
                    $stats['created']++;
                } else {
                    $event->forceFill($attributes)->save();
                    $stats['updated']++;
                }

                if ($claimed) {
                    return;
                }

                // Keyed on the sport too: a source's game ids are per-league,
                // so without it an NBA sync claims the row an NHL game holds.
                EventExternalId::query()->updateOrCreate(
                    [
                        'source' => $source,
                        'external_id' => $game->sourceEventId,
                        'sport' => $game->sport->value,
                    ],
                    ['event_id' => $event->id],
                );
            });
        }

        return $stats;
    }

    /**
     * The event this game refers to, and whether the source already
     * claims it.
     *
     * The caller needs both. Matching on a claim row proves the claim
     * exists and points here, so re-writing it is a round trip spent to
     * change nothing - on the path that every already-known game takes.
     *
     * @return array{event: ?Event, claimed: bool}
     */
    private function findEvent(ScheduleGame $game, string $source, int $homeId, int $awayId): array
    {
        /*
         * Joined rather than looked up and then fetched. The id and the
         * row are one question, and asking it as two cost a round trip a
         * game on a path every game takes.
         */
        $byExternal = Event::query()
            ->select('events.*')
            ->join('event_external_ids as claim', 'claim.event_id', '=', 'events.id')
            ->where('claim.source', $source)
            ->where('claim.external_id', $game->sourceEventId)
            // Per-league ids again: an unscoped lookup hands back whichever
            // sport claimed this number first.
            ->where('claim.sport', $game->sport->value)
            ->first();

        if ($byExternal !== null) {
            return ['event' => $byExternal, 'claimed' => true];
        }

        // Fall back to the natural key for sources that have no stable id, or
        // whose id changed. Two guards keep this from merging distinct games:
        //
        //  - a tight window, which absorbs a rescheduled start time but not
        //    the next meeting of the same two teams;
        //  - and skipping any event this source has already claimed under a
        //    different id, which is what separates the two halves of a
        //    doubleheader and consecutive games of a postseason series.
        $natural = Event::query()
            ->where('sport', $game->sport->value)
            ->where('home_team_id', $homeId)
            ->where('away_team_id', $awayId)
            ->whereBetween('starts_at', [
                $game->startsAt->subHours(12),
                $game->startsAt->addHours(12),
            ])
            ->where('status', '!=', EventStatus::Canceled->value)
            ->whereNotExists(function ($query) use ($source): void {
                $query->select(DB::raw(1))
                    ->from('event_external_ids')
                    ->whereColumn('event_external_ids.event_id', 'events.id')
                    ->where('event_external_ids.source', $source);
            })
            ->first();

        return ['event' => $natural, 'claimed' => false];
    }

    /**
     * The venue, created on first sight and filled in as the sources say
     * more about it.
     *
     * Venues are reference data rather than history, so unlike almost
     * everything else here they are updated in place. Only empty columns
     * are filled: a coordinate worked out by the locate command must not be
     * wiped by a later schedule pull that happens to carry no address, and
     * a roof flag set by hand must outlast a feed that goes quiet about it.
     */
    /**
     * Whether a feed has told us anything about this venue beyond its
     * name, which is the only reason to read the row.
     */
    private function carriesVenueDetail(ScheduleGame $game): bool
    {
        return $game->venueCity !== null
            || $game->venueRegion !== null
            || $game->venueIndoor !== null
            || $game->roof !== null;
    }

    /** @return array<string, int> */
    private function venueIds(): array
    {
        if ($this->venueIds === null) {
            $this->venueIds = Venue::query()
                ->pluck('id', 'name')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        return $this->venueIds;
    }

    private function venueId(ScheduleGame $game): ?int
    {
        if ($game->venueName === null || trim($game->venueName) === '') {
            return null;
        }

        $name = trim($game->venueName);

        /*
         * A stadium already on file, from a feed with nothing new to say
         * about it, is answered from the map. CFBD sends a venue name and
         * nothing else, so for NCAAF that is every game.
         *
         * When the feed does carry detail the row is read as before,
         * because the enrichment below needs the model and skipping it
         * would quietly stop venues ever learning their city or roof.
         */
        if (! $this->carriesVenueDetail($game)) {
            $known = $this->venueIds();

            if (isset($known[$name])) {
                return $known[$name];
            }
        }

        $venue = Venue::query()->firstOrCreate(['name' => $name]);

        $this->venueIds = ($this->venueIds ?? []) + [$name => $venue->id];

        $fill = [];

        if ($venue->city === null && $game->venueCity !== null) {
            $fill['city'] = $game->venueCity;
        }

        if ($venue->region === null && $game->venueRegion !== null) {
            $fill['region'] = mb_substr($game->venueRegion, 0, 64);
        }

        // is_dome defaults to false in the schema, so "never been told" and
        // "told it is open" look identical on the row. A feed saying so is
        // therefore allowed to set it true, and nothing sets it back.
        //
        // Two feeds can say it. ESPN states `indoor` outright; nflverse
        // gives a roof word instead, and it is the only one of the two that
        // answers for the NFL, which is the sport where a roof decides
        // whether weather is worth fetching at all.
        if (! $venue->is_dome && ($game->venueIndoor === true || $this->roofIsCovered($game->roof))) {
            $fill['is_dome'] = true;
        }

        if ($fill !== []) {
            $venue->forceFill($fill)->save();
        }

        return $venue->id;
    }

    /**
     * Whether a roof word means the game is played under cover.
     *
     * nflverse publishes four: outdoors, dome, closed and open. "Closed" is
     * a retractable roof that was shut, "open" one that was not, so only
     * dome and closed are covered - and "open" must not be read as a dome
     * merely because the stadium has a roof it did not use.
     */
    private function roofIsCovered(?string $roof): bool
    {
        return in_array(mb_strtolower(trim((string) $roof)), ['dome', 'closed'], true);
    }
}
