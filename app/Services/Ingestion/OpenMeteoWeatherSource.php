<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Models\Event;
use App\Models\Venue;
use App\Models\WeatherReading;
use Illuminate\Support\Carbon;

/**
 * Weather at kickoff, from Open-Meteo. Keyless, and the one missing feature
 * source with a real archive behind it.
 *
 * Forecasts only, and fetched before kickoff. That is a deliberate limit
 * and it is the same one NflverseSource already applies: the nflverse
 * games file carries the observed game-time temperature and wind for every
 * NFL game since 1999 and that adapter refuses to read them, on the
 * grounds that they are recorded after the fact and training on them would
 * be "leakage wearing a weather costume". The reasoning holds here. What a
 * bettor has before a game is a forecast, with a forecast's error in it; a
 * model trained on what the weather turned out to be would look sharper in
 * a backtest than it could ever be in use.
 *
 * Open-Meteo also publishes an archive of past forecast *runs*, and that
 * one is usable, which is what makes weather the only one of the three
 * missing feature sources with a trainable history. Its previous-runs
 * endpoint returns a separate series per run - `temperature_2m` beside
 * `temperature_2m_previous_day1` and `_previous_day3` - and the values
 * genuinely differ between them, which is the evidence that they are
 * distinct forecasts rather than one number repeated. A `_previous_day3`
 * reading for a game is therefore what the forecast said three days out:
 * knowable in advance, and provenance that can be stated.
 *
 * Coverage is not uniform and is not assumed to be. Temperature runs go
 * back to at least 2022; wind runs thin out before 2024, and a variable
 * the archive does not carry for a date comes back absent rather than
 * zero. The reanalysis archive of what the weather actually *was* is still
 * not used, for the reason above.
 *
 * Every reading records the lead time it belongs to, because a fixed lead
 * is the only way a training set and a live board measure the same thing.
 * Training on whatever forecast happened to be newest and then predicting
 * from a six-day-out guess compares two different quantities and calls the
 * difference skill.
 *
 * Covered games are skipped outright. A dome has weather and it is always
 * the same weather, so a reading there is a row that costs a request and
 * carries no information.
 *
 * Whether a game is covered is read off the *event* first and the venue
 * only as a fallback, because a retractable roof makes it a fact about the
 * game rather than the building. The schedule data bears this out: NRG
 * Stadium has 48 games with the roof closed and 3 with it open, Lucas Oil
 * 43 and 8. A venue-level flag has to call those either all indoor or all
 * outdoor, and both answers are wrong for some of the games.
 */
class OpenMeteoWeatherSource
{
    use MakesHttpRequests;

    private const FORECAST = 'https://api.open-meteo.com/v1/forecast';

    private const PREVIOUS_RUNS = 'https://previous-runs-api.open-meteo.com/v1/forecast';

    private const HOURLY = 'temperature_2m,relative_humidity_2m,precipitation,precipitation_probability,'
        .'wind_speed_10m,wind_direction_10m,wind_gusts_10m,surface_pressure';

    public function key(): string
    {
        return 'open_meteo';
    }

    /**
     * Forecasts for games still to come at outdoor venues with coordinates.
     *
     * @return array{written: int, skipped_indoor: int, skipped_unlocated: int}
     */
    public function syncUpcoming(int $horizonHours = 168): array
    {
        $events = Event::query()
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHours($horizonHours))
            ->with('venue')
            ->orderBy('starts_at')
            ->get();

        return $this->sync($events);
    }

    /**
     * What the forecast said a fixed number of days before kickoff, for
     * games already played.
     *
     * This is the trainable half, and the lead time is the argument that
     * makes it trainable rather than leaky: the series asked for is the
     * model run issued that many days ahead, so nothing in it was known
     * after the game. One request per game, because the run series are
     * only served per location and date.
     *
     * Games already carrying a reading at this lead are skipped, so the
     * command can be run repeatedly over a season without duplicating it
     * or spending the requests again.
     *
     * @return array{written: int, skipped_indoor: int, skipped_unlocated: int, no_data: int}
     */
    public function backfillForecasts(Carbon $from, Carbon $to, int $leadDays = 1): array
    {
        $events = Event::query()
            ->whereBetween('starts_at', [$from, $to])
            ->where('starts_at', '<', now())
            ->whereDoesntHave('weatherReadings', fn ($query) => $query->where('lead_days', $leadDays))
            ->with('venue')
            ->orderBy('starts_at')
            ->get();

        $written = 0;
        $indoor = 0;
        $unlocated = 0;
        $noData = 0;

        foreach ($events as $event) {
            $venue = $event->venue;

            if ($venue === null || $venue->lat === null || $venue->lon === null) {
                $unlocated++;

                continue;
            }

            if ($this->isCovered($event, $venue)) {
                $indoor++;

                continue;
            }

            $reading = $this->previousRunReading(
                $event,
                (float) $venue->lat,
                (float) $venue->lon,
                $leadDays,
            );

            // The archive does not carry every variable for every date.
            // A game it cannot answer for gets no row, rather than a row
            // of nulls that would read as calm, dry weather.
            if ($reading === null) {
                $noData++;

                continue;
            }

            WeatherReading::create([
                'event_id' => $event->id,
                'venue_id' => $venue->id,
                'is_forecast' => true,
                'lead_days' => $leadDays,
                'run' => 'previous_day'.$leadDays,
                'valid_at' => $event->starts_at,
                'source' => $this->key(),
                // When we asked, which for a backfill is long after the
                // game. lead_days is what says the numbers predate it.
                'fetched_at' => now(),
            ] + $reading);

            $written++;
        }

        return [
            'written' => $written,
            'skipped_indoor' => $indoor,
            'skipped_unlocated' => $unlocated,
            'no_data' => $noData,
        ];
    }

    /**
     * @param  iterable<int, Event>  $events
     * @return array{written: int, skipped_indoor: int, skipped_unlocated: int}
     */
    private function sync(iterable $events): array
    {
        $written = 0;
        $indoor = 0;
        $unlocated = 0;

        foreach ($events as $event) {
            $venue = $event->venue;

            if ($venue === null || $venue->lat === null || $venue->lon === null) {
                $unlocated++;

                continue;
            }

            if ($this->isCovered($event, $venue)) {
                $indoor++;

                continue;
            }

            $reading = $this->readingFor($event, (float) $venue->lat, (float) $venue->lon);

            if ($reading === null) {
                continue;
            }

            WeatherReading::create([
                'event_id' => $event->id,
                'venue_id' => $venue->id,
                // Always a forecast here. See the class note.
                'is_forecast' => true,
                // Which run this is, so a feature can ask for one lead time
                // rather than whatever happened to be newest.
                'lead_days' => max(0, (int) floor(now()->diffInDays($event->starts_at, absolute: false))),
                'run' => 'live',
                'valid_at' => $event->starts_at,
                'source' => $this->key(),
                'fetched_at' => now(),
            ] + $reading);

            $written++;
        }

        return [
            'written' => $written,
            'skipped_indoor' => $indoor,
            'skipped_unlocated' => $unlocated,
        ];
    }

    /**
     * Whether this game was played under cover.
     *
     * The event's own roof wins when the schedule published one, because a
     * retractable roof is a fact about the game: NRG Stadium has 48 games
     * closed and 3 open. "open" is a roof that was not used, so the game
     * was outdoors and its weather matters.
     *
     * The venue flag is the fallback, for sports whose schedules publish no
     * roof word at all.
     */
    private function isCovered(Event $event, Venue $venue): bool
    {
        $roof = mb_strtolower(trim((string) $event->roof));

        if ($roof !== '') {
            return in_array($roof, ['dome', 'closed'], true);
        }

        return (bool) $venue->is_dome;
    }

    /**
     * One game's weather as a forecast issued `$leadDays` before it.
     *
     * The previous-runs endpoint names each run as a suffix on the
     * variable, so the fields asked for carry the lead in their names and
     * the response has to be read back out of those same names.
     *
     * Returns null when the archive carries nothing usable for the date -
     * which happens, and varies by variable. A row of nulls would read as
     * a calm, dry evening.
     *
     * @return array<string, float|int|string|null>|null
     */
    private function previousRunReading(Event $event, float $lat, float $lon, int $leadDays): ?array
    {
        $suffix = '_previous_day'.$leadDays;

        $fields = [
            'temp_f' => 'temperature_2m',
            'humidity' => 'relative_humidity_2m',
            'precip_in' => 'precipitation',
            'wind_mph' => 'wind_speed_10m',
            'wind_deg' => 'wind_direction_10m',
            'gust_mph' => 'wind_gusts_10m',
            'pressure_mb' => 'surface_pressure',
        ];

        $kickoff = $event->starts_at->copy()->utc();

        $payload = $this->getJson(self::PREVIOUS_RUNS, [
            'latitude' => $lat,
            'longitude' => $lon,
            'hourly' => implode(',', array_map(
                static fn (string $field) => $field.$suffix,
                array_values($fields),
            )),
            'temperature_unit' => 'fahrenheit',
            'wind_speed_unit' => 'mph',
            'precipitation_unit' => 'inch',
            'timezone' => 'UTC',
            'start_date' => $kickoff->toDateString(),
            'end_date' => $kickoff->toDateString(),
        ]);

        $hourly = (array) ($payload['hourly'] ?? []);
        $index = $this->nearestHour((array) ($hourly['time'] ?? []), $kickoff);

        if ($index === null) {
            return null;
        }

        $reading = [];

        foreach ($fields as $column => $field) {
            $reading[$column] = $column === 'wind_deg'
                ? $this->intValue($hourly, $field.$suffix, $index)
                : $this->value($hourly, $field.$suffix, $index);
        }

        // Temperature is the one variable the archive reaches furthest back
        // on. Without it there is nothing worth calling a reading.
        if ($reading['temp_f'] === null) {
            return null;
        }

        // Probability is not published per run, so it stays absent rather
        // than being borrowed from a different run.
        $reading['precip_prob'] = null;

        return $reading;
    }

    /**
     * The hour containing kickoff, pulled out of an hourly series.
     *
     * Asked for in UTC and matched in UTC. Open-Meteo will happily answer
     * in a local timezone, and a series whose timezone is assumed rather
     * than stated is how a reading ends up attached to the wrong hour -
     * which, for an evening kickoff, can be the difference between a calm
     * afternoon and the front that arrived at dusk.
     *
     * @return array<string, float|int|string|null>|null
     */
    private function readingFor(Event $event, float $lat, float $lon): ?array
    {
        $kickoff = $event->starts_at->copy()->utc();
        $date = $kickoff->toDateString();

        $query = [
            'latitude' => $lat,
            'longitude' => $lon,
            'hourly' => self::HOURLY,
            'temperature_unit' => 'fahrenheit',
            'wind_speed_unit' => 'mph',
            'precipitation_unit' => 'inch',
            'timezone' => 'UTC',
            'start_date' => $date,
            'end_date' => $date,
        ];

        $payload = $this->getJson(self::FORECAST, $query);
        $hourly = (array) ($payload['hourly'] ?? []);
        $times = (array) ($hourly['time'] ?? []);

        if ($times === []) {
            return null;
        }

        $index = $this->nearestHour($times, $kickoff);

        if ($index === null) {
            return null;
        }

        return [
            'temp_f' => $this->value($hourly, 'temperature_2m', $index),
            'humidity' => $this->value($hourly, 'relative_humidity_2m', $index),
            'precip_in' => $this->value($hourly, 'precipitation', $index),
            'precip_prob' => $this->value($hourly, 'precipitation_probability', $index),
            'wind_mph' => $this->value($hourly, 'wind_speed_10m', $index),
            'wind_deg' => $this->intValue($hourly, 'wind_direction_10m', $index),
            'gust_mph' => $this->value($hourly, 'wind_gusts_10m', $index),
            'pressure_mb' => $this->value($hourly, 'surface_pressure', $index),
        ];
    }

    /**
     * The series entry closest to kickoff, and only if it is genuinely
     * close. A game at the edge of the archive can come back with a series
     * that stops hours short, and the nearest entry to a kickoff that is
     * not covered is not a reading of it.
     *
     * @param  array<int, mixed>  $times
     */
    private function nearestHour(array $times, Carbon $kickoff): ?int
    {
        $best = null;
        $bestDistance = null;

        foreach ($times as $index => $time) {
            if (! is_string($time)) {
                continue;
            }

            try {
                $at = Carbon::parse($time, 'UTC');
            } catch (\Throwable) {
                continue;
            }

            $distance = abs($at->diffInMinutes($kickoff, absolute: true));

            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $index;
            }
        }

        return $bestDistance !== null && $bestDistance <= 90 ? $best : null;
    }

    /** @param  array<string, mixed>  $hourly */
    private function value(array $hourly, string $field, int $index): ?float
    {
        $value = $hourly[$field][$index] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /** @param  array<string, mixed>  $hourly */
    private function intValue(array $hourly, string $field, int $index): ?int
    {
        $value = $this->value($hourly, $field, $index);

        return $value === null ? null : (int) round($value);
    }
}
