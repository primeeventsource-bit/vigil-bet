<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\Sport;
use App\Models\Event;
use App\Models\Venue;

/**
 * Puts venues on the map, so weather has somewhere to point.
 *
 * Open-Meteo's geocoder answers for places, not buildings: "Highmark
 * Stadium" means nothing to it, "Orchard Park, New York" lands within a
 * couple of miles. That is the accuracy on offer and it is enough for what
 * it is for. Temperature, wind and precipitation at a stadium and at the
 * town around it are the same weather; a kilometre of error does not move
 * a total. Nothing here should be read as a stadium-precise coordinate,
 * and the altitude that comes back is the town's, not the field's.
 *
 * A venue with no city cannot be located at all, and is left alone rather
 * than guessed at from its name. The schedule sources carry the city, so
 * the fix for those is another schedule pull, not a cleverer geocoder.
 */
class VenueLocator
{
    use MakesHttpRequests;

    private const GEOCODER = 'https://geocoding-api.open-meteo.com/v1/search';

    private const ESPN_TEAMS = 'https://site.api.espn.com/apis/site/v2/sports';

    private const MLB_VENUES = 'https://statsapi.mlb.com/api/v1/venues';

    public function key(): string
    {
        return 'open_meteo';
    }

    /**
     * Fill in what each sport's own feed knows about its venues, before
     * falling back to geocoding a town name.
     *
     * Worth doing in this order because the feeds are better. MLB publishes
     * the stadium's actual coordinates, which beats a geocoded town by a
     * couple of miles and costs one request for the whole league. ESPN does
     * not give coordinates but does give the city and, more valuably,
     * whether the place has a roof - and `is_dome` defaults to false, so
     * without that every dome in the file looks like an open stadium and
     * gets a pointless forecast.
     *
     * @return array{located: int, roofed: int, cities: int}
     */
    public function enrichFromFeeds(): array
    {
        $located = $this->locateMlbVenues();
        [$cities, $roofed] = $this->enrichFromEspn();

        return ['located' => $located, 'roofed' => $roofed, 'cities' => $cities];
    }

    /**
     * MLB publishes the stadium coordinates themselves. One request for the
     * league, and the result is the field rather than the town.
     */
    private function locateMlbVenues(): int
    {
        $payload = $this->getJson(self::MLB_VENUES, ['hydrate' => 'location', 'sportId' => 1]);
        $filled = 0;

        foreach ((array) ($payload['venues'] ?? []) as $remote) {
            $name = $remote['name'] ?? null;
            $coords = $remote['location']['defaultCoordinates'] ?? null;

            if (! is_string($name) || ! is_array($coords)) {
                continue;
            }

            $venue = Venue::query()->where('name', $name)->first();

            if ($venue === null || $venue->lat !== null) {
                continue;
            }

            $venue->forceFill(array_filter([
                'lat' => $coords['latitude'] ?? null,
                'lon' => $coords['longitude'] ?? null,
                'city' => $remote['location']['city'] ?? null,
                'region' => $remote['location']['stateAbbrev'] ?? null,
                'altitude_m' => isset($remote['location']['elevation'])
                    ? (int) round((float) $remote['location']['elevation'] * 0.3048)
                    : null,
            ], static fn ($value) => $value !== null))->save();

            $filled++;
        }

        return $filled;
    }

    /**
     * City and roof from ESPN, matched on venue name.
     *
     * ESPN's team *list* does not carry the venue - only the per-team
     * endpoint does - so this costs one request per team. That is fine for
     * the four professional leagues, which is about 120 requests for a
     * command run by hand once; it is not fine for the college sports,
     * where it would be several hundred and where almost every venue is
     * either indoors or not one we hold. So the college leagues are left
     * to the schedule pull, which carries the same fields for whichever
     * games actually appear.
     *
     * Matched by name because our venues were created by whichever source
     * owns that sport's schedule, and only some of those are ESPN.
     *
     * @return array{0: int, 1: int} cities filled, roofs set
     */
    private function enrichFromEspn(): array
    {
        $cities = 0;
        $roofed = 0;

        foreach (self::PRO_LEAGUES as $sportKey) {
            $sport = Sport::tryFrom($sportKey);
            $path = $sport?->config('espn_path');

            if (! is_string($path)) {
                continue;
            }

            foreach ($this->espnTeamIds($path) as $teamId) {
                $remote = $this->getJson(self::ESPN_TEAMS.'/'.$path.'/teams/'.$teamId)['team']['franchise']['venue'] ?? null;

                if (! is_array($remote) || ! is_string($remote['fullName'] ?? null)) {
                    continue;
                }

                $venue = $this->venueNamed($remote['fullName']);

                if ($venue === null) {
                    continue;
                }

                $fill = [];

                if ($venue->city === null && is_string($remote['address']['city'] ?? null)) {
                    $fill['city'] = $remote['address']['city'];
                    $cities++;
                }

                if ($venue->region === null && is_string($remote['address']['state'] ?? null)) {
                    $fill['region'] = mb_substr($remote['address']['state'], 0, 64);
                }

                // Only ever set true. False is also the column default, so
                // a feed that goes quiet must not be able to open a roof.
                if (($remote['indoor'] ?? false) === true && ! $venue->is_dome) {
                    $fill['is_dome'] = true;
                    $roofed++;
                }

                if ($fill !== []) {
                    $venue->forceFill($fill)->save();
                }
            }
        }

        return [$cities, $roofed];
    }

    /**
     * A venue by name, allowing for the qualifiers a feed hangs off it.
     *
     * ESPN publishes "Highmark Stadium (Old)" for a stadium our schedule
     * source simply calls "Highmark Stadium", so an exact match misses a
     * current venue over a parenthetical. The parenthetical is stripped and
     * the comparison is case- and punctuation-insensitive.
     *
     * No fuzzy matching beyond that. Two stadiums in the same city with
     * similar names are a real thing, and a venue attached to the wrong
     * coordinates produces confident weather for the wrong place, which is
     * worse than no weather at all.
     */
    private function venueNamed(string $name): ?Venue
    {
        if ($venue = Venue::query()->where('name', $name)->first()) {
            return $venue;
        }

        $target = $this->comparableName($name);

        if ($target === '') {
            return null;
        }

        return Venue::query()
            ->get(['id', 'name', 'city', 'region', 'lat', 'lon', 'is_dome'])
            ->first(fn (Venue $venue) => $this->comparableName($venue->name) === $target);
    }

    private function comparableName(string $name): string
    {
        // Drop any trailing "(...)" qualifier, then everything that is not
        // a letter or a digit.
        $stripped = (string) preg_replace('/\s*\([^)]*\)\s*$/', '', $name);

        return mb_strtolower((string) preg_replace('/[^a-z0-9]/i', '', $stripped));
    }

    /** @return array<int, string> */
    private function espnTeamIds(string $path): array
    {
        $payload = $this->getJson(self::ESPN_TEAMS.'/'.$path.'/teams', ['limit' => 400]);
        $ids = [];

        foreach ((array) ($payload['sports'][0]['leagues'][0]['teams'] ?? []) as $entry) {
            if (isset($entry['team']['id'])) {
                $ids[] = (string) $entry['team']['id'];
            }
        }

        return $ids;
    }

    /**
     * The leagues worth spending a request per team on. The college sports
     * are deliberately absent: see enrichFromEspn.
     */
    private const PRO_LEAGUES = ['nfl', 'mlb', 'nba', 'nhl'];

    /**
     * City and region for venues no feed reports. See supplyKnownCities.
     *
     * Region is given only for the United States, where the geocoder needs
     * it to tell two cities of the same name apart. Elsewhere the city is
     * unambiguous enough that the most populous match is the right one.
     *
     * Previous names are kept pointing at the place they always were: a
     * stadium that changed sponsor did not move, so the coordinates are
     * the same and the historical rows stay usable.
     */
    private const KNOWN_CITIES = [
        // Stadiums under a previous sponsor's name.
        'CenturyLink Field' => ['Seattle', 'WA'],
        'FedExField' => ['Landover', 'MD'],
        'FirstEnergy Stadium' => ['Cleveland', 'OH'],
        'GEHA Field at Arrowhead Stadium' => ['Kansas City', 'MO'],
        'Heinz Field' => ['Pittsburgh', 'PA'],
        'Mercedes-Benz Superdome' => ['New Orleans', 'LA'],
        'New Era Field' => ['Orchard Park', 'NY'],
        'Paul Brown Stadium' => ['Cincinnati', 'OH'],
        'TIAA Bank Stadium' => ['Jacksonville', 'FL'],

        // Current venues the team payloads happened not to carry.
        'NRG Stadium' => ['Houston', 'TX'],
        'SoFi Stadium' => ['Inglewood', 'CA'],
        'American Family Field' => ['Milwaukee', 'WI'],
        'Tropicana Field' => ['St. Petersburg', 'FL'],

        // Neutral sites for international games, which belong to no team
        // and so appear in no team's payload.
        'Allianz Arena' => ['Munich', null],
        'Arena Corinthians' => ['Sao Paulo', null],
        'Azteca Stadium' => ['Mexico City', null],
        'Deutsche Bank Park' => ['Frankfurt am Main', null],
        'Stade de France' => ['Saint-Denis', null],
        'Tottenham Stadium' => ['London', null],
        'Wembley Stadium' => ['London', null],
    ];

    /**
     * Supply the city for venues the feeds no longer report.
     *
     * Two kinds end up here, and between them they account for most of the
     * historical slate: stadiums under a previous sponsor's name, which
     * nflverse keeps for its twenty-six years of history but ESPN only
     * reports under the current one, and the neutral sites of
     * international games, which belong to no team and so appear in no
     * team's payload.
     *
     * Only the city and region are supplied, and only because a stadium's
     * city is a plain geographic fact. The roof is deliberately not
     * asserted here from memory - several of these are domes and getting
     * one wrong would attach a wind reading to an indoor game. That comes
     * from the schedule feeds, which publish it per game.
     *
     * @return array{filled: int}
     */
    public function supplyKnownCities(): array
    {
        $filled = 0;

        foreach (self::KNOWN_CITIES as $name => [$city, $region]) {
            $venue = $this->venueNamed($name);

            if ($venue === null || $venue->city !== null) {
                continue;
            }

            $venue->forceFill(array_filter([
                'city' => $city,
                'region' => $region,
            ], static fn ($value) => $value !== null))->save();

            $filled++;
        }

        return ['filled' => $filled];
    }

    /**
     * Mark the fixed domes, from the roof words the schedules published.
     *
     * Derived rather than asserted. The roof is on the events already, from
     * the sources that publish it per game, and reading it back beats
     * anyone's memory of which stadiums have lids - SoFi Stadium and the
     * Mercedes-Benz Superdome both sat marked outdoor until this ran, which
     * would have attached a wind reading to 150 indoor games.
     *
     * A venue counts as a fixed dome only when every game there with a
     * known roof was covered. That is what keeps retractables out: NRG
     * Stadium has 48 closed and 3 open, Lucas Oil 43 and 8, and calling
     * either a dome would be wrong for the open ones. Those are decided per
     * game instead, which is where a retractable roof belongs.
     *
     * @return array{marked: int}
     */
    public function markFixedDomes(): array
    {
        $marked = 0;

        $venues = Venue::query()->where('is_dome', false)->get(['id', 'name', 'is_dome']);

        foreach ($venues as $venue) {
            $roofs = Event::query()
                ->where('venue_id', $venue->id)
                ->whereNotNull('roof')
                ->distinct()
                ->pluck('roof')
                ->map(fn ($roof) => mb_strtolower(trim((string) $roof)))
                ->filter()
                ->unique()
                ->values();

            if ($roofs->isEmpty() || $roofs->contains(fn (string $roof) => $roof !== 'dome')) {
                continue;
            }

            $venue->forceFill(['is_dome' => true])->save();
            $marked++;
        }

        return ['marked' => $marked];
    }

    /**
     * Fill in coordinates for every venue missing them.
     *
     * @return array{located: int, skipped: int, unmatched: int}
     */
    public function locateAll(bool $force = false): array
    {
        $located = 0;
        $skipped = 0;
        $unmatched = 0;

        $venues = Venue::query()
            ->when(! $force, fn ($query) => $query->whereNull('lat'))
            ->orderBy('id')
            ->get();

        foreach ($venues as $venue) {
            if ($venue->city === null || trim((string) $venue->city) === '') {
                $skipped++;

                continue;
            }

            if ($this->locate($venue)) {
                $located++;
            } else {
                $unmatched++;
            }
        }

        return ['located' => $located, 'skipped' => $skipped, 'unmatched' => $unmatched];
    }

    /** True when the venue now has coordinates. */
    public function locate(Venue $venue): bool
    {
        $match = $this->search($venue->city, $venue->region);

        if ($match === null) {
            return false;
        }

        $venue->forceFill(array_filter([
            'lat' => $match['latitude'] ?? null,
            'lon' => $match['longitude'] ?? null,
            'country' => isset($match['country_code']) ? mb_substr((string) $match['country_code'], 0, 2) : null,
            'tz' => $match['timezone'] ?? null,
            'altitude_m' => isset($match['elevation']) ? (int) round((float) $match['elevation']) : null,
        ], static fn ($value) => $value !== null))->save();

        return $venue->lat !== null;
    }

    /**
     * The best match for a city, preferring one in the right region.
     *
     * The geocoder returns several Springfields and sorts by population,
     * not by relevance to what was asked, so the region is used to pick
     * between them and the first result is only accepted when it cannot be
     * narrowed. Without that, half the American venues end up in the wrong
     * state and the weather is confidently wrong rather than missing.
     *
     * @return array<string, mixed>|null
     */
    private function search(?string $city, ?string $region): ?array
    {
        $city = trim((string) $city);

        if ($city === '') {
            return null;
        }

        $results = (array) ($this->getJson(self::GEOCODER, [
            'name' => $city,
            'count' => 10,
            'language' => 'en',
            'format' => 'json',
        ])['results'] ?? []);

        if ($results === []) {
            return null;
        }

        $region = trim((string) $region);

        if ($region !== '') {
            foreach ($results as $result) {
                if ($this->matchesRegion($result, $region)) {
                    return $result;
                }
            }
        }

        return $results[0];
    }

    /**
     * Whether a geocoder result sits in the region the feed named.
     *
     * The feeds publish two-letter codes and the geocoder publishes full
     * names, so the code is expanded before comparing and both forms are
     * accepted either way round.
     *
     * @param  array<string, mixed>  $result
     */
    private function matchesRegion(array $result, string $region): bool
    {
        $wanted = [mb_strtolower($region)];

        if ($full = self::STATE_CODES[mb_strtoupper($region)] ?? null) {
            $wanted[] = mb_strtolower($full);
        }

        foreach ([$result['admin1'] ?? null, $result['admin1_code'] ?? null] as $candidate) {
            if (is_string($candidate) && in_array(mb_strtolower($candidate), $wanted, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The feeds publish two-letter codes and the geocoder publishes full
     * names, so one of them has to be translated. Only the states that
     * actually host a venue in these six sports are listed, plus the
     * Canadian provinces the NHL plays in.
     */
    private const STATE_CODES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
        'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
        'DC' => 'Washington, D.C.', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
        'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
        'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
        'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
        'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
        'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
        'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
        'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
        'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
        'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
        'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
        'AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba',
        'ON' => 'Ontario', 'QC' => 'Quebec',
    ];
}
