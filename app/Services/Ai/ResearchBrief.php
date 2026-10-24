<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * What is known about the two teams, for a Read to explain.
 *
 * A Vigil Read used to receive prices, line movement, team names and the
 * model's verdict - and nothing else. On an unproven market, which is
 * every market, that left it explaining a number nobody should act on
 * and saying little a customer could use.
 *
 * §0.3 says an unproven market appears as "analysis and tools, labelled
 * not yet proven", and §4 says the daily card should carry near-misses
 * and Reads as a feature rather than an empty state. Analysis needs
 * something to analyse. This is it: form from box scores, who is
 * unavailable, how much rest each side has had, and the weather.
 *
 * Every figure here is marked as context rather than input, and that
 * distinction is the whole point. The engine's probability is an Elo
 * rating and nothing else - it has never seen an injury report. A Read
 * that said "the price reflects the quarterback being out" would be
 * describing a mechanism that does not exist, which is the same failure
 * as inventing a number. So the sheet says plainly which facts the model
 * used and which are for the reader to weigh themselves.
 */
class ResearchBrief
{
    /** Games of form to look back over. Five is a fortnight in most sports. */
    private const FORM_GAMES = 5;

    /** How stale an injury report may be before it is not news. */
    private const INJURY_DAYS = 7;

    /**
     * @return array<string, mixed>
     */
    public function forEvent(int $eventId): array
    {
        $event = Event::query()
            ->with(['homeTeam:id,display_name', 'awayTeam:id,display_name'])
            ->find($eventId);

        if ($event === null) {
            return [];
        }

        $brief = [];

        foreach (['home' => $event->home_team_id, 'away' => $event->away_team_id] as $side => $teamId) {
            if ($teamId === null) {
                continue;
            }

            $brief += $this->form($side, (int) $teamId, $event);
            $brief += $this->injuries($side, (int) $teamId, (string) $event->sport->value);
            $brief += $this->rest($side, (int) $teamId, $event);
        }

        $brief += $this->weather($event);

        /*
         * Said once, in the sheet, rather than left for the prompt to
         * remember. Everything above is observation; the engine's number
         * is a power rating and has seen none of it.
         */
        if ($brief !== []) {
            $brief['research_in_model'] = false;
            $brief['research_note'] = 'These figures describe the teams. The engine\'s probability is a '
                .'power rating built from results only - it has not seen any of them.';
        }

        return array_filter($brief, static fn ($value) => $value !== null && $value !== []);
    }

    /**
     * Recent results, from box scores we hold.
     *
     * Points scored and allowed rather than sport-specific categories:
     * every sport has them, they mean the same thing in all of them, and
     * a Read that compared third-down rate in hockey would be worse than
     * one that said nothing.
     *
     * @return array<string, mixed>
     */
    private function form(string $side, int $teamId, Event $event): array
    {
        $games = DB::table('team_game_stats as t')
            ->join('events as e', 'e.id', '=', 't.event_id')
            ->where('t.team_id', $teamId)
            ->where('e.starts_at', '<', $event->starts_at)
            ->whereNotNull('t.points')
            ->whereNotNull('t.points_allowed')
            ->orderByDesc('e.starts_at')
            ->limit(self::FORM_GAMES)
            ->get(['t.points', 't.points_allowed']);

        if ($games->isEmpty()) {
            return [];
        }

        $wins = $games->filter(fn ($g) => (int) $g->points > (int) $g->points_allowed)->count();
        $played = $games->count();

        return [
            $side.'_form_games' => $played,
            $side.'_form_record' => $wins.'-'.($played - $wins),
            $side.'_form_points_for' => round($games->avg('points'), 1),
            $side.'_form_points_against' => round($games->avg('points_allowed'), 1),
        ];
    }

    /**
     * Who is unavailable, as the feed last said.
     *
     * One row per player - the latest report - because a feed restates a
     * player's status every day and a Read listing the same name six
     * times reads as six injuries.
     *
     * Only the statuses that mean a player probably is not playing.
     * "Probable" on a sixth-string guard is noise, and a sheet full of
     * noise is how a Read ends up describing the wrong thing.
     *
     * @return array<string, mixed>
     */
    private function injuries(string $side, int $teamId, string $sport): array
    {
        $rows = DB::table('injury_reports as i')
            ->where('i.team_id', $teamId)
            ->where('i.sport', $sport)
            ->where('i.reported_at', '>=', now()->subDays(self::INJURY_DAYS))
            /*
             * The statuses the feed actually uses are active, out,
             * questionable, doubtful and probable. Out and doubtful are
             * the two that mean a player probably is not playing;
             * questionable is counted below but not named, because
             * twenty questionable names is noise and a sheet full of
             * noise is how a Read ends up describing the wrong thing.
             */
            ->whereIn('i.status', ['out', 'doubtful'])
            ->whereNotExists(
                fn ($later) => $later->selectRaw('1')
                    ->from('injury_reports as l')
                    ->whereColumn('l.team_id', 'i.team_id')
                    ->whereColumn('l.player_name', 'i.player_name')
                    ->whereColumn('l.reported_at', '>', 'i.reported_at')
            )
            ->orderBy('i.player_name')
            ->limit(8)
            ->get(['i.player_name', 'i.position', 'i.status']);

        if ($rows->isEmpty()) {
            return [];
        }

        return [
            $side.'_unavailable_count' => $rows->count(),
            $side.'_unavailable' => $rows
                ->map(fn ($r) => trim(($r->player_name ?? '').' ('.($r->position ?: '?').', '.$r->status.')'))
                ->implode(', '),
            $side.'_questionable_count' => $this->questionableCount($teamId, $sport),
        ];
    }

    /**
     * How many are questionable, counted but not named.
     *
     * Worth a number because it says something about a squad, and not
     * worth a list because a Read has 120 words and twenty names would
     * spend all of them on players who will probably play.
     */
    private function questionableCount(int $teamId, string $sport): ?int
    {
        $count = DB::table('injury_reports as i')
            ->where('i.team_id', $teamId)
            ->where('i.sport', $sport)
            ->where('i.reported_at', '>=', now()->subDays(self::INJURY_DAYS))
            ->where('i.status', 'questionable')
            ->whereNotExists(
                fn ($later) => $later->selectRaw('1')
                    ->from('injury_reports as l')
                    ->whereColumn('l.team_id', 'i.team_id')
                    ->whereColumn('l.player_name', 'i.player_name')
                    ->whereColumn('l.reported_at', '>', 'i.reported_at')
            )
            ->count();

        return $count > 0 ? $count : null;
    }

    /**
     * Days since this team last played.
     *
     * Computed from the schedule rather than read from events.home_rest_days,
     * which is populated for the NFL and almost nothing else. The
     * schedule is the thing that knows, and it knows for every sport.
     *
     * @return array<string, mixed>
     */
    private function rest(string $side, int $teamId, Event $event): array
    {
        $previous = DB::table('events')
            ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->where('starts_at', '<', $event->starts_at)
            ->where('status', 'final')
            ->orderByDesc('starts_at')
            ->value('starts_at');

        if ($previous === null) {
            return [];
        }

        /*
         * abs(), because diffInDays is signed: the earlier date is the
         * argument, so this comes back negative, and "-3 days rest"
         * would be quoted into a Read exactly as written.
         */
        $days = (int) abs($event->starts_at->diffInDays($previous));

        return [$side.'_rest_days' => $days];
    }

    /** @return array<string, mixed> */
    private function weather(Event $event): array
    {
        $reading = DB::table('weather_readings')
            ->where('event_id', $event->id)
            // The latest fetch for this game. A forecast is revised, and
            // the revision is the one worth quoting.
            ->orderByDesc('fetched_at')
            ->first();

        if ($reading === null) {
            return [];
        }

        return array_filter([
            'weather_temp_f' => $reading->temp_f === null ? null : (int) round((float) $reading->temp_f),
            'weather_wind_mph' => $reading->wind_mph === null ? null : (int) round((float) $reading->wind_mph),
            'weather_gust_mph' => $reading->gust_mph === null ? null : (int) round((float) $reading->gust_mph),
            'weather_precip_chance' => $reading->precip_prob === null ? null : (int) round((float) $reading->precip_prob),
            'weather_conditions' => $reading->conditions,
            'weather_is_forecast' => (bool) $reading->is_forecast,
        ], static fn ($value) => $value !== null);
    }
}
