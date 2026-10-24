<?php

declare(strict_types=1);

use App\Data\ScheduleGame;
use App\Enums\Sport;
use App\Exceptions\AppendOnlyViolation;
use App\Models\Event;
use App\Models\Team;
use App\Models\Venue;
use App\Models\WeatherReading;
use App\Services\Ingestion\EventSynchronizer;
use App\Services\Ingestion\OpenMeteoWeatherSource;
use App\Services\Ingestion\VenueLocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/*
 * Payload shapes captured from the live endpoints: Open-Meteo returns
 * parallel arrays under `hourly`, one of times and one per variable, and
 * the index into them is the only thing tying a reading to an hour.
 */

/*
 * Nine in the morning, every run.
 *
 * These tests place a kickoff with now()->addDays(N)->setTime(18, 0) and
 * then assert the lead time is N days. That only holds while the clock is
 * earlier in the day than the kickoff hour: run this at half past six in
 * the evening and the gap is N days minus a few hours, which floors to
 * N - 1 and the test fails having found nothing wrong.
 *
 * The date stays real, so nothing that depends on a forecast being in the
 * future changes. Only the time of day is pinned.
 */
beforeEach(function () {
    $this->travelTo(now()->startOfDay()->addHours(9));
});

function hourlyFor(string $date, array $overrides = []): array
{
    $times = [];
    $temps = [];
    $winds = [];

    for ($hour = 0; $hour < 24; $hour++) {
        $times[] = sprintf('%sT%02d:00', $date, $hour);
        // A distinct value per hour, so a test can tell which one was read.
        $temps[] = 40.0 + $hour;
        $winds[] = $hour;
    }

    return array_merge([
        'time' => $times,
        'temperature_2m' => $temps,
        'wind_speed_10m' => $winds,
        'wind_direction_10m' => array_fill(0, 24, 270),
        'wind_gusts_10m' => array_fill(0, 24, 25.5),
        'precipitation' => array_fill(0, 24, 0.1),
        'precipitation_probability' => array_fill(0, 24, 40),
        'relative_humidity_2m' => array_fill(0, 24, 65),
        'surface_pressure' => array_fill(0, 24, 1013.2),
    ], $overrides);
}

function fakeForecast(array $hourly): void
{
    Http::fake(['api.open-meteo.com/*' => Http::response(['hourly' => $hourly])]);
}

function outdoorVenue(array $overrides = []): Venue
{
    return Venue::create(array_merge([
        'name' => 'Highmark Stadium',
        'city' => 'Orchard Park',
        'region' => 'NY',
        'lat' => 42.7738,
        'lon' => -78.7870,
        'is_dome' => false,
    ], $overrides));
}

function gameAt(Venue $venue, string $startsAt): Event
{
    $home = Team::firstOrCreate(
        ['sport' => 'nfl', 'slug' => 'buffalo-bills'],
        ['name' => 'Buffalo Bills', 'display_name' => 'Buffalo Bills'],
    );
    $away = Team::firstOrCreate(
        ['sport' => 'nfl', 'slug' => 'new-york-jets'],
        ['name' => 'New York Jets', 'display_name' => 'New York Jets'],
    );

    return Event::create([
        'sport' => 'nfl',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'venue_id' => $venue->id,
        'starts_at' => $startsAt,
        'status' => 'scheduled',
    ]);
}

it('stores the forecast for the hour the game kicks off in', function () {
    $venue = outdoorVenue();
    $kickoff = now()->addDays(2)->setTime(18, 0);
    gameAt($venue, $kickoff->toDateTimeString());

    fakeForecast(hourlyFor($kickoff->toDateString()));

    $stats = app(OpenMeteoWeatherSource::class)->syncUpcoming();

    $reading = WeatherReading::sole();

    expect($stats['written'])->toBe(1)
        // Hour 18 of the series, not hour 0 and not the daily average.
        ->and($reading->temp_f)->toBe(58.0)
        ->and($reading->wind_mph)->toBe(18.0)
        ->and($reading->wind_deg)->toBe(270)
        ->and($reading->gust_mph)->toBe(25.5)
        ->and($reading->precip_prob)->toBe(40.0);
});

it('marks it as a forecast and stamps when it was fetched', function () {
    $venue = outdoorVenue();
    $kickoff = now()->addDays(2)->setTime(18, 0);
    gameAt($venue, $kickoff->toDateTimeString());

    fakeForecast(hourlyFor($kickoff->toDateString()));

    app(OpenMeteoWeatherSource::class)->syncUpcoming();

    $reading = WeatherReading::sole();

    expect($reading->is_forecast)->toBeTrue()
        // Fetched before the game, which is what makes it usable at all.
        ->and($reading->fetched_at->lessThan($reading->valid_at))->toBeTrue();
});

it('skips a dome, where the weather is always the same weather', function () {
    $venue = outdoorVenue(['name' => 'Ford Field', 'is_dome' => true]);
    gameAt($venue, now()->addDays(2)->setTime(18, 0)->toDateTimeString());

    Http::fake();

    $stats = app(OpenMeteoWeatherSource::class)->syncUpcoming();

    expect($stats['skipped_indoor'])->toBe(1)
        ->and($stats['written'])->toBe(0);

    Http::assertNothingSent();
});

it('skips a venue with no coordinates and says how many', function () {
    $venue = outdoorVenue(['lat' => null, 'lon' => null]);
    gameAt($venue, now()->addDays(2)->setTime(18, 0)->toDateTimeString());

    Http::fake();

    $stats = app(OpenMeteoWeatherSource::class)->syncUpcoming();

    expect($stats['skipped_unlocated'])->toBe(1)
        ->and($stats['written'])->toBe(0);

    Http::assertNothingSent();
});

it('leaves a game already played alone', function () {
    $venue = outdoorVenue();
    gameAt($venue, now()->subDays(2)->toDateTimeString());

    Http::fake();

    expect(app(OpenMeteoWeatherSource::class)->syncUpcoming()['written'])->toBe(0);

    // Forward only. Observed game-time weather is deliberately not
    // collected: a model trained on what the weather turned out to be
    // would look sharper in a backtest than it could ever be in use.
    Http::assertNothingSent();
});

it('leaves a game beyond the horizon alone', function () {
    $venue = outdoorVenue();
    gameAt($venue, now()->addDays(20)->toDateTimeString());

    Http::fake();

    expect(app(OpenMeteoWeatherSource::class)->syncUpcoming(168)['written'])->toBe(0);
});

it('refuses a series that does not actually cover kickoff', function () {
    // A game at the edge of what the forecast reaches comes back with a
    // series that stops short. The nearest hour to an uncovered kickoff is
    // not a reading of it.
    $venue = outdoorVenue();
    $kickoff = now()->addDays(2)->setTime(18, 0);
    gameAt($venue, $kickoff->toDateTimeString());

    fakeForecast([
        'time' => [$kickoff->copy()->subDays(3)->format('Y-m-d\TH:00')],
        'temperature_2m' => [50.0],
    ]);

    expect(app(OpenMeteoWeatherSource::class)->syncUpcoming()['written'])->toBe(0);
});

it('writes a new row each run rather than replacing the last', function () {
    // A forecast three days out and one three hours out are different
    // facts, and the gap between them is the error a model lives with.
    $venue = outdoorVenue();
    $kickoff = now()->addDays(2)->setTime(18, 0);
    gameAt($venue, $kickoff->toDateTimeString());

    fakeForecast(hourlyFor($kickoff->toDateString()));

    app(OpenMeteoWeatherSource::class)->syncUpcoming();
    app(OpenMeteoWeatherSource::class)->syncUpcoming();

    expect(WeatherReading::count())->toBe(2);
});

it('never rewrites a reading', function () {
    $venue = outdoorVenue();
    $kickoff = now()->addDays(2)->setTime(18, 0);
    gameAt($venue, $kickoff->toDateTimeString());

    fakeForecast(hourlyFor($kickoff->toDateString()));
    app(OpenMeteoWeatherSource::class)->syncUpcoming();

    $reading = WeatherReading::sole();
    $reading->temp_f = 1.0;
    $reading->save();
})->throws(AppendOnlyViolation::class);

/*
 * Putting venues on the map.
 */

it('matches a venue past the qualifier a feed hangs off its name', function () {
    // ESPN says "Highmark Stadium (Old)" for a stadium our schedule source
    // simply calls "Highmark Stadium". An exact match loses a current
    // venue over a parenthetical.
    $venue = outdoorVenue(['lat' => null, 'lon' => null, 'city' => null, 'region' => null]);

    Http::fake([
        'statsapi.mlb.com/*' => Http::response(['venues' => []]),
        '*/teams?*' => Http::response(['sports' => [['leagues' => [['teams' => [
            ['team' => ['id' => '2']],
        ]]]]]]),
        '*/teams/2' => Http::response(['team' => ['franchise' => ['venue' => [
            'fullName' => 'Highmark Stadium (Old)',
            'address' => ['city' => 'Orchard Park', 'state' => 'NY'],
            'indoor' => false,
        ]]]]),
        'geocoding-api.open-meteo.com/*' => Http::response(['results' => []]),
    ]);

    app(VenueLocator::class)->enrichFromFeeds();

    expect($venue->fresh()->city)->toBe('Orchard Park');
});

it('takes exact stadium coordinates from the MLB feed', function () {
    $venue = Venue::create(['name' => 'Yankee Stadium', 'is_dome' => false]);

    Http::fake([
        'statsapi.mlb.com/*' => Http::response(['venues' => [[
            'name' => 'Yankee Stadium',
            'location' => [
                'city' => 'Bronx',
                'stateAbbrev' => 'NY',
                'elevation' => 55,
                'defaultCoordinates' => ['latitude' => 40.82919482, 'longitude' => -73.9264977],
            ],
        ]]]),
        '*/teams?*' => Http::response(['sports' => [['leagues' => [['teams' => []]]]]]),
    ]);

    app(VenueLocator::class)->enrichFromFeeds();

    $venue->refresh();

    expect((float) $venue->lat)->toEqualWithDelta(40.8292, 0.001)
        ->and((float) $venue->lon)->toEqualWithDelta(-73.9265, 0.001)
        ->and($venue->city)->toBe('Bronx')
        // Feet in the feed, metres in the column.
        ->and($venue->altitude_m)->toBe(17);
});

it('turns a roof on but never off', function () {
    // is_dome shares its default with "never been told", so a feed going
    // quiet must not be able to open a roof.
    $venue = Venue::create(['name' => 'Ford Field', 'is_dome' => true]);

    Http::fake([
        'statsapi.mlb.com/*' => Http::response(['venues' => []]),
        '*/teams?*' => Http::response(['sports' => [['leagues' => [['teams' => [
            ['team' => ['id' => '8']],
        ]]]]]]),
        '*/teams/8' => Http::response(['team' => ['franchise' => ['venue' => [
            'fullName' => 'Ford Field',
            'address' => ['city' => 'Detroit', 'state' => 'MI'],
            'indoor' => false,
        ]]]]),
    ]);

    app(VenueLocator::class)->enrichFromFeeds();

    expect($venue->fresh()->is_dome)->toBeTrue();
});

it('geocodes the town, preferring the right state over the biggest match', function () {
    // The geocoder sorts by population, not by relevance. Without the
    // region half the American venues land in the wrong state and the
    // weather is confidently wrong rather than missing.
    $venue = Venue::create([
        'name' => 'Some Stadium', 'city' => 'Springfield', 'region' => 'MO', 'is_dome' => false,
    ]);

    Http::fake(['geocoding-api.open-meteo.com/*' => Http::response(['results' => [
        ['name' => 'Springfield', 'admin1' => 'Illinois', 'latitude' => 39.8, 'longitude' => -89.6],
        ['name' => 'Springfield', 'admin1' => 'Missouri', 'latitude' => 37.2, 'longitude' => -93.3],
    ]])]);

    app(VenueLocator::class)->locateAll();

    expect((float) $venue->fresh()->lat)->toEqualWithDelta(37.2, 0.01);
});

it('leaves a venue with no city alone rather than geocoding its name', function () {
    $venue = Venue::create(['name' => 'Wembley Stadium', 'is_dome' => false]);

    Http::fake();

    $stats = app(VenueLocator::class)->locateAll();

    expect($stats['skipped'])->toBe(1)
        ->and($venue->fresh()->lat)->toBeNull();

    Http::assertNothingSent();
});

/*
 * The roof word, which is the only thing that answers for the NFL.
 */

it('reads a covered roof off the schedule word', function (string $roof, bool $covered) {
    $home = Team::firstOrCreate(
        ['sport' => 'nfl', 'slug' => 'buffalo-bills'],
        ['name' => 'Buffalo Bills', 'display_name' => 'Buffalo Bills'],
    );
    $away = Team::firstOrCreate(
        ['sport' => 'nfl', 'slug' => 'new-york-jets'],
        ['name' => 'New York Jets', 'display_name' => 'New York Jets'],
    );

    app(EventSynchronizer::class)->sync([
        new ScheduleGame(
            sport: Sport::Nfl,
            sourceEventId: 'roof-'.$roof,
            homeTeamName: 'Buffalo Bills',
            awayTeamName: 'New York Jets',
            startsAt: CarbonImmutable::now()->addDays(3),
            homeTeamExternalId: (string) $home->id,
            awayTeamExternalId: (string) $away->id,
            venueName: 'Roof Test Stadium '.$roof,
            roof: $roof,
        ),
    ], 'test');

    expect(Venue::where('name', 'Roof Test Stadium '.$roof)->sole()->is_dome)->toBe($covered);
})->with([
    // nflverse publishes these four words.
    ['dome', true],
    ['closed', true],
    ['outdoors', false],
    // A retractable roof that was not used is not a dome.
    ['open', false],
]);

/*
 * Past forecast runs: the trainable half, and the lead time that makes it
 * trainable rather than leaky.
 */

function previousRunHourly(string $date, int $lead, array $overrides = []): array
{
    $suffix = '_previous_day'.$lead;
    $times = [];
    $temps = [];

    for ($hour = 0; $hour < 24; $hour++) {
        $times[] = sprintf('%sT%02d:00', $date, $hour);
        $temps[] = 30.0 + $hour;
    }

    return array_merge([
        'time' => $times,
        'temperature_2m'.$suffix => $temps,
        'wind_speed_10m'.$suffix => array_fill(0, 24, 14.0),
        'wind_direction_10m'.$suffix => array_fill(0, 24, 200),
        'wind_gusts_10m'.$suffix => array_fill(0, 24, 22.0),
        'precipitation'.$suffix => array_fill(0, 24, 0.05),
        'relative_humidity_2m'.$suffix => array_fill(0, 24, 70),
        'surface_pressure'.$suffix => array_fill(0, 24, 1008.0),
    ], $overrides);
}

function fakePreviousRuns(array $hourly): void
{
    Http::fake(['previous-runs-api.open-meteo.com/*' => Http::response(['hourly' => $hourly])]);
}

it('stores what the forecast said a fixed number of days before kickoff', function () {
    $venue = outdoorVenue();
    $kickoff = now()->subDays(30)->setTime(18, 0);
    gameAt($venue, $kickoff->toDateTimeString());

    fakePreviousRuns(previousRunHourly($kickoff->toDateString(), 1));

    $stats = app(OpenMeteoWeatherSource::class)
        ->backfillForecasts(now()->subDays(60), now(), 1);

    $reading = WeatherReading::sole();

    expect($stats['written'])->toBe(1)
        ->and($reading->temp_f)->toBe(48.0)
        ->and($reading->wind_mph)->toBe(14.0)
        // The row names the run it came from, so the provenance claim sits
        // on the row rather than only in a comment.
        ->and($reading->lead_days)->toBe(1)
        ->and($reading->run)->toBe('previous_day1')
        ->and($reading->is_forecast)->toBeTrue();
});

it('asks for the run it was told to, not whichever is newest', function () {
    $venue = outdoorVenue();
    $kickoff = now()->subDays(30)->setTime(18, 0);
    gameAt($venue, $kickoff->toDateTimeString());

    fakePreviousRuns(previousRunHourly($kickoff->toDateString(), 3));

    app(OpenMeteoWeatherSource::class)->backfillForecasts(now()->subDays(60), now(), 3);

    expect(WeatherReading::sole()->lead_days)->toBe(3);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'temperature_2m_previous_day3'));
});

it('writes no row when the archive carries no temperature for the date', function () {
    // 2023 and earlier carry temperature runs but not wind. A date it
    // cannot answer for at all gets nothing, because a row of nulls would
    // read as a calm, dry evening.
    $venue = outdoorVenue();
    $kickoff = now()->subDays(30)->setTime(18, 0);
    gameAt($venue, $kickoff->toDateTimeString());

    fakePreviousRuns([
        'time' => [$kickoff->format('Y-m-d\TH:00')],
        'temperature_2m_previous_day1' => [null],
    ]);

    $stats = app(OpenMeteoWeatherSource::class)
        ->backfillForecasts(now()->subDays(60), now(), 1);

    expect($stats['no_data'])->toBe(1)
        ->and(WeatherReading::count())->toBe(0);
});

it('keeps a reading whose wind the archive does not carry', function () {
    // Temperature alone is still worth having, and the missing fields stay
    // null rather than becoming zero.
    $venue = outdoorVenue();
    $kickoff = now()->subDays(30)->setTime(18, 0);
    gameAt($venue, $kickoff->toDateTimeString());

    $hourly = previousRunHourly($kickoff->toDateString(), 1);
    unset($hourly['wind_speed_10m_previous_day1'], $hourly['precipitation_previous_day1']);

    fakePreviousRuns($hourly);

    app(OpenMeteoWeatherSource::class)->backfillForecasts(now()->subDays(60), now(), 1);

    $reading = WeatherReading::sole();

    expect($reading->temp_f)->toBe(48.0)
        ->and($reading->wind_mph)->toBeNull()
        ->and($reading->precip_in)->toBeNull();
});

it('does not fetch the same lead twice for a game', function () {
    $venue = outdoorVenue();
    $kickoff = now()->subDays(30)->setTime(18, 0);
    gameAt($venue, $kickoff->toDateTimeString());

    fakePreviousRuns(previousRunHourly($kickoff->toDateString(), 1));

    app(OpenMeteoWeatherSource::class)->backfillForecasts(now()->subDays(60), now(), 1);
    app(OpenMeteoWeatherSource::class)->backfillForecasts(now()->subDays(60), now(), 1);

    expect(WeatherReading::count())->toBe(1);
});

it('leaves a game still to come out of the backfill', function () {
    $venue = outdoorVenue();
    gameAt($venue, now()->addDays(3)->toDateTimeString());

    Http::fake();

    expect(app(OpenMeteoWeatherSource::class)
        ->backfillForecasts(now()->subDays(60), now()->addDays(10), 1)['written'])->toBe(0);
});

/*
 * A retractable roof is a fact about the game, not the building.
 */

it('skips a game played with the roof closed, at an outdoor venue', function () {
    // NRG Stadium: 48 games closed, 3 open. A venue-level flag has to call
    // those all one thing, and either answer is wrong for some of them.
    $venue = outdoorVenue(['name' => 'NRG Stadium']);
    $event = gameAt($venue, now()->addDays(2)->setTime(18, 0)->toDateTimeString());
    $event->forceFill(['roof' => 'closed'])->save();

    Http::fake();

    $stats = app(OpenMeteoWeatherSource::class)->syncUpcoming();

    expect($stats['skipped_indoor'])->toBe(1);

    Http::assertNothingSent();
});

it('fetches a game played with the roof open', function () {
    $venue = outdoorVenue(['name' => 'NRG Stadium']);
    $kickoff = now()->addDays(2)->setTime(18, 0);
    $event = gameAt($venue, $kickoff->toDateTimeString());
    $event->forceFill(['roof' => 'open'])->save();

    fakeForecast(hourlyFor($kickoff->toDateString()));

    expect(app(OpenMeteoWeatherSource::class)->syncUpcoming()['written'])->toBe(1);
});

it('prefers the event roof over a venue wrongly marked indoor', function () {
    $venue = outdoorVenue(['name' => 'Lucas Oil Stadium', 'is_dome' => true]);
    $kickoff = now()->addDays(2)->setTime(18, 0);
    $event = gameAt($venue, $kickoff->toDateTimeString());
    $event->forceFill(['roof' => 'open'])->save();

    fakeForecast(hourlyFor($kickoff->toDateString()));

    expect(app(OpenMeteoWeatherSource::class)->syncUpcoming()['written'])->toBe(1);
});

it('falls back to the venue when the schedule published no roof', function () {
    $venue = outdoorVenue(['is_dome' => true]);
    $event = gameAt($venue, now()->addDays(2)->setTime(18, 0)->toDateTimeString());
    $event->forceFill(['roof' => null])->save();

    Http::fake();

    expect(app(OpenMeteoWeatherSource::class)->syncUpcoming()['skipped_indoor'])->toBe(1);
});

it('records the lead time on a live forecast too', function () {
    $venue = outdoorVenue();
    $kickoff = now()->addDays(4)->setTime(18, 0);
    gameAt($venue, $kickoff->toDateTimeString());

    fakeForecast(hourlyFor($kickoff->toDateString()));

    app(OpenMeteoWeatherSource::class)->syncUpcoming();

    expect(WeatherReading::sole()->lead_days)->toBe(4)
        ->and(WeatherReading::sole()->run)->toBe('live');
});

it('asks for one lead time so training and the live board agree', function () {
    $venue = outdoorVenue();
    $kickoff = now()->subDays(10)->setTime(18, 0);
    $event = gameAt($venue, $kickoff->toDateTimeString());

    foreach ([1, 5] as $lead) {
        WeatherReading::create([
            'event_id' => $event->id,
            'venue_id' => $venue->id,
            'is_forecast' => true,
            'lead_days' => $lead,
            'run' => 'previous_day'.$lead,
            'valid_at' => $event->starts_at,
            'temp_f' => 40.0 + $lead,
            'source' => 'open_meteo',
            'fetched_at' => now(),
        ]);
    }

    // The one-day run, not the five-day guess, and not a blend.
    expect(WeatherReading::query()->atLeadDays(1)->first()->temp_f)->toBe(41.0)
        // Nothing better than asked for: a game missing the 1-day run
        // falls back to a less informed one, never a later one.
        ->and(WeatherReading::query()->atLeadDays(3)->first()->temp_f)->toBe(45.0);
});

/*
 * Roofs derived from the schedules, not recalled.
 */

function roofedGame(Venue $venue, string $roof, int $minutes): void
{
    $home = Team::firstOrCreate(
        ['sport' => 'nfl', 'slug' => 'roof-home'],
        ['name' => 'Roof Home', 'display_name' => 'Roof Home'],
    );
    $away = Team::firstOrCreate(
        ['sport' => 'nfl', 'slug' => 'roof-away'],
        ['name' => 'Roof Away', 'display_name' => 'Roof Away'],
    );

    Event::create([
        'sport' => 'nfl',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'venue_id' => $venue->id,
        'starts_at' => now()->subDays(40)->addMinutes($minutes),
        'status' => 'final',
        'roof' => $roof,
    ]);
}

it('marks a venue whose every game was under a dome', function () {
    // SoFi Stadium and the Mercedes-Benz Superdome both sat marked outdoor
    // until this ran: 150 indoor games that would have been given wind.
    $venue = Venue::create(['name' => 'Fixed Dome Stadium', 'is_dome' => false]);

    foreach (range(1, 5) as $i) {
        roofedGame($venue, 'dome', $i);
    }

    expect(app(VenueLocator::class)->markFixedDomes()['marked'])->toBe(1)
        ->and($venue->fresh()->is_dome)->toBeTrue();
});

it('leaves a retractable alone, because its roof is a per-game fact', function () {
    // NRG Stadium: 48 closed, 3 open. Calling it a dome is wrong for the
    // open games, and calling it outdoors is wrong for the other 48.
    $venue = Venue::create(['name' => 'Retractable Stadium', 'is_dome' => false]);

    foreach (range(1, 5) as $i) {
        roofedGame($venue, 'closed', $i);
    }

    roofedGame($venue, 'open', 6);

    expect(app(VenueLocator::class)->markFixedDomes()['marked'])->toBe(0)
        ->and($venue->fresh()->is_dome)->toBeFalse();
});

it('leaves an outdoor venue alone', function () {
    $venue = Venue::create(['name' => 'Open Air Stadium', 'is_dome' => false]);

    foreach (range(1, 5) as $i) {
        roofedGame($venue, 'outdoors', $i);
    }

    expect($venue->fresh()->is_dome)->toBeFalse();
});

it('says nothing about a venue whose games carry no roof word', function () {
    // Absent is not the same as open.
    $venue = Venue::create(['name' => 'Unknown Roof Stadium', 'is_dome' => false]);

    roofedGame($venue, '', 1);

    expect(app(VenueLocator::class)->markFixedDomes()['marked'])->toBe(0)
        ->and($venue->fresh()->is_dome)->toBeFalse();
});

/*
 * Cities for venues no feed reports.
 */

it('supplies the city for a stadium under a previous sponsor name', function () {
    // nflverse keeps these across twenty-six years; ESPN reports only the
    // current name, so no feed answers for them.
    $venue = Venue::create(['name' => 'Heinz Field', 'is_dome' => false]);

    expect(app(VenueLocator::class)->supplyKnownCities()['filled'])->toBeGreaterThan(0)
        ->and($venue->fresh()->city)->toBe('Pittsburgh')
        ->and($venue->fresh()->region)->toBe('PA');
});

it('supplies the city for an international neutral site', function () {
    $venue = Venue::create(['name' => 'Wembley Stadium', 'is_dome' => false]);

    app(VenueLocator::class)->supplyKnownCities();

    expect($venue->fresh()->city)->toBe('London')
        // No region: outside the United States the city is unambiguous
        // enough that the geocoder's top match is the right one.
        ->and($venue->fresh()->region)->toBeNull();
});

it('never overwrites a city a feed already supplied', function () {
    $venue = Venue::create(['name' => 'Heinz Field', 'city' => 'Somewhere Else', 'is_dome' => false]);

    app(VenueLocator::class)->supplyKnownCities();

    expect($venue->fresh()->city)->toBe('Somewhere Else');
});

it('asserts no roofs by hand, because getting one wrong is expensive', function () {
    // Several of the hand-supplied venues are domes. The roof comes from
    // the schedules, which publish it per game; a wrong guess here would
    // attach a wind reading to an indoor game.
    $venue = Venue::create(['name' => 'SoFi Stadium', 'is_dome' => false]);

    app(VenueLocator::class)->supplyKnownCities();

    expect($venue->fresh()->city)->toBe('Inglewood')
        ->and($venue->fresh()->is_dome)->toBeFalse();
});
