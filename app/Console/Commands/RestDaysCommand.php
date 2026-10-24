<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SeasonType;
use App\Enums\Sport;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Works out how much rest each side had, from the schedule itself.
 *
 * `home_rest_days` and `away_rest_days` arrive from the ingestion feed,
 * and only the NFL feed supplies them. Every other sport had null for
 * every game: 3,353 NBA games, 2,304 NHL, 3,447 MLB, 1,418 NCAAB,
 * 10,455 NCAAF.
 *
 * That is not a small gap. Rest is the dominant schedule effect in
 * basketball - a team on no days rest is measurably worse, and the
 * back-to-back is the most worked angle in the sport. The model had
 * three features for it (`rest_diff`, `home_short_week`,
 * `away_short_week`) and all three were constant zero, so the
 * regularizer dropped them. The backtest output reads
 * `rest_diff +0.0000`, which looks like "no signal" and actually meant
 * "no data".
 *
 * None of this needs a feed. Rest is the gap between a team's previous
 * game and this one, and both dates are already in the events table.
 *
 * Leakage is not a concern: the schedule is published weeks ahead, so
 * the interval between two fixtures is knowable long before either is
 * played.
 */
class RestDaysCommand extends Command
{
    protected $signature = 'edge:schedule:rest
                            {sport? : One sport key, or every sport when omitted}
                            {--force : Recompute rest days that are already set}
                            {--dry-run : Report what would change and change nothing}';

    protected $description = 'Derive home and away rest days from the schedule for every sport';

    /**
     * A gap longer than this is a season break, not rest.
     *
     * Reporting 118 days of rest for a season opener would be true and
     * useless: the model would read it as an enormous advantage for
     * whichever side happened to open at home, when in fact both sides
     * are equally fresh and equally rusty.
     */
    private const MAX_REST_DAYS = 30;

    public function handle(): int
    {
        $sports = $this->argument('sport') !== null
            ? [Sport::from((string) $this->argument('sport'))]
            : Sport::cases();

        $dry = (bool) $this->option('dry-run');
        $totalFilled = 0;

        foreach ($sports as $sport) {
            $filled = $this->forSport($sport, $dry);
            $totalFilled += $filled;

            $this->components->twoColumnDetail(
                strtoupper($sport->value),
                number_format($filled).' side(s) given a rest figure',
            );
        }

        $this->newLine();

        $this->components->info($dry
            ? 'Dry run. Nothing changed.'
            : number_format($totalFilled).' rest figures written.');

        return self::SUCCESS;
    }

    private function forSport(Sport $sport, bool $dry): int
    {
        /*
         * Every competitive fixture in start order, so "the previous
         * game" means what it says. Preseason is excluded to match what
         * the training set uses - a team that played a preseason game
         * yesterday is not meaningfully tired, and counting it would put
         * a back-to-back flag on a game nobody rested for.
         */
        $events = Event::query()
            ->where('sport', $sport->value)
            ->where(fn ($q) => $q->whereNull('season_type')
                ->orWhere('season_type', '!=', SeasonType::Preseason->value))
            ->whereNotNull('starts_at')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get(['id', 'home_team_id', 'away_team_id', 'starts_at', 'home_rest_days', 'away_rest_days']);

        /** @var array<int, Carbon> $lastPlayed */
        $lastPlayed = [];
        $updates = [];

        foreach ($events as $event) {
            foreach (['home' => $event->home_team_id, 'away' => $event->away_team_id] as $side => $teamId) {
                if ($teamId === null) {
                    continue;
                }

                $column = $side.'_rest_days';
                $previous = $lastPlayed[$teamId] ?? null;

                if ($previous !== null && ($event->{$column} === null || $this->option('force'))) {
                    $days = (int) floor($previous->diffInRealHours($event->starts_at) / 24);

                    // A negative or absurd gap means the schedule rows are
                    // out of order or duplicated, which is worth leaving
                    // alone rather than encoding.
                    if ($days >= 0 && $days <= self::MAX_REST_DAYS) {
                        $updates[$event->id][$column] = $days;
                    }
                }

                $lastPlayed[$teamId] = $event->starts_at;
            }
        }

        if ($dry || $updates === []) {
            return count($updates, COUNT_RECURSIVE) - count($updates);
        }

        /*
         * Grouped by value, not one statement per event.
         *
         * The obvious loop - update each event in turn - is 40,000 round
         * trips across six sports, which is exactly the mistake that made
         * MarkClosingLines time out and die on max attempts. Rest is a
         * small integer between 0 and 30, so every event sharing a value
         * can be set in one statement: about sixty queries a sport rather
         * than ten thousand.
         */
        $written = 0;

        foreach (['home_rest_days', 'away_rest_days'] as $column) {
            $byValue = [];

            foreach ($updates as $eventId => $columns) {
                if (isset($columns[$column])) {
                    $byValue[$columns[$column]][] = $eventId;
                }
            }

            foreach ($byValue as $days => $eventIds) {
                foreach (array_chunk($eventIds, 2_000) as $chunk) {
                    Event::query()->whereIn('id', $chunk)->update([$column => $days]);
                    $written += count($chunk);
                }
            }
        }

        return $written;
    }
}
