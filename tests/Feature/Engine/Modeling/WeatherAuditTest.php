<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\Side;
use App\Enums\Sport;
use App\Models\Book;
use App\Models\Event;
use App\Models\EventLineMarker;
use App\Models\Team;
use App\Models\Venue;
use App\Models\WeatherReading;
use App\Services\Modeling\WeatherAudit;

/*
 * The audit answers one question: does weather predict the total beyond
 * what the closing line already says. These build boards where the answer
 * is known by construction, which is the only way to tell a working audit
 * from one that is quietly reporting an artefact of its own arithmetic.
 */

function auditVenue(): Venue
{
    return Venue::firstOrCreate(
        ['name' => 'Audit Field'],
        ['city' => 'Buffalo', 'region' => 'NY', 'lat' => 42.88, 'lon' => -78.87, 'is_dome' => false],
    );
}

function auditTeams(): array
{
    return [
        Team::firstOrCreate(['sport' => 'nfl', 'slug' => 'audit-home'], ['name' => 'Audit Home', 'display_name' => 'Audit Home']),
        Team::firstOrCreate(['sport' => 'nfl', 'slug' => 'audit-away'], ['name' => 'Audit Away', 'display_name' => 'Audit Away']),
    ];
}

/**
 * One finished game with a forecast, a result and optionally a closing
 * total. $minutes keeps the start times distinct: events carry a unique
 * natural key of sport, start time and the two teams.
 */
function auditGame(int $minutes, ?float $wind, int $totalPoints, ?float $closingTotal = null): Event
{
    [$home, $away] = auditTeams();

    $event = Event::create([
        'sport' => 'nfl',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'venue_id' => auditVenue()->id,
        'starts_at' => now()->subDays(60)->addMinutes($minutes),
        'status' => 'final',
        'final_home_score' => (int) floor($totalPoints / 2),
        'final_away_score' => $totalPoints - (int) floor($totalPoints / 2),
    ]);

    WeatherReading::create([
        'event_id' => $event->id,
        'venue_id' => $event->venue_id,
        'is_forecast' => true,
        'lead_days' => 1,
        'run' => 'previous_day1',
        'valid_at' => $event->starts_at,
        'temp_f' => 50.0,
        'wind_mph' => $wind,
        'precip_in' => 0.0,
        'source' => 'open_meteo',
        'fetched_at' => now(),
    ]);

    if ($closingTotal !== null) {
        $book = Book::firstOrCreate(['key' => 'auditbook'], ['title' => 'Audit Book', 'is_tracked' => true]);

        EventLineMarker::create([
            'event_id' => $event->id,
            'book_id' => $book->id,
            'market' => Market::Total->value,
            'side' => Side::Over->value,
            'line_close' => $closingTotal,
            'computed_at' => now(),
        ]);
    }

    return $event;
}

it('says plainly when there is nothing to measure', function () {
    $result = app(WeatherAudit::class)->run(Sport::Nfl, 1);

    expect($result['games'])->toBe(0)
        ->and($result['variables'])->toBe([])
        ->and($result['notes'][0])->toContain('No games');
});

it('finds an effect that is really in the scores', function () {
    // Wind built to cost 3 points per 10 mph, with no closing line to
    // price it, so the raw effect is all there is to find.
    foreach (range(0, 59) as $i) {
        $wind = ($i % 20) * 1.5;
        auditGame($i, $wind, (int) round(45 - ($wind * 0.3)));
    }

    $wind = app(WeatherAudit::class)->run(Sport::Nfl, 1)['variables']['wind_mph'];

    expect($wind['actual_total']['n'])->toBe(60)
        ->and($wind['actual_total']['r'])->toBeLessThan(-0.8)
        // Reported in points per mph, so 10 mph is about three points.
        ->and($wind['actual_total']['slope'] * 10)->toBeLessThan(-2.0);
});

it('shows an effect the market has fully priced as leaving nothing', function () {
    // Scores fall 3 points per 10 mph AND the closing total falls with
    // them. The residual is flat by construction, which is the real-world
    // answer for weather and the one the audit has to be able to give.
    foreach (range(0, 59) as $i) {
        $wind = ($i % 20) * 1.5;
        $total = 45 - ($wind * 0.3);
        auditGame($i, $wind, (int) round($total), closingTotal: $total);
    }

    $wind = app(WeatherAudit::class)->run(Sport::Nfl, 1)['variables']['wind_mph'];

    expect($wind['closing_total']['r'])->toBeLessThan(-0.8)
        // Priced in: nothing survives the closing line.
        ->and(abs($wind['residual']['t']))->toBeLessThan(2.0);
});

it('finds an effect the market has only half priced', function () {
    // Scores fall 3 points per 10 mph, the line only moves 1.5. Half the
    // effect is left, and an audit that could not see it would be useless.
    foreach (range(0, 59) as $i) {
        $wind = ($i % 20) * 1.5;
        auditGame($i, $wind, (int) round(45 - ($wind * 0.3)), closingTotal: 45 - ($wind * 0.15));
    }

    $wind = app(WeatherAudit::class)->run(Sport::Nfl, 1)['variables']['wind_mph'];

    expect($wind['residual']['r'])->toBeLessThan(-0.5)
        ->and(abs($wind['residual']['t']))->toBeGreaterThan(2.0);
});

it('reads the scores off the columns that actually hold them', function () {
    // The bug this replaced: the audit asked for home_score and away_score,
    // which do not exist on this table, so every total came out zero. A
    // constant total correlates with nothing, and the residual then came
    // back as exactly minus the closing-line figure - a number that looked
    // like a finding and was an artefact.
    auditGame(1, 5.0, 51);

    foreach (range(2, 40) as $i) {
        auditGame($i, ($i % 15) * 1.2, 40 + ($i % 11));
    }

    $totals = app(WeatherAudit::class)->run(Sport::Nfl, 1);

    expect($totals['games'])->toBe(40)
        // The giveaway was a dead-flat actual_total. It must not be flat.
        ->and($totals['variables']['wind_mph']['actual_total']['r'])->not->toBe(0.0);
});

it('ignores a game with no result yet', function () {
    auditGame(1, 10.0, 44);

    $unfinished = auditGame(2, 10.0, 44);
    $unfinished->forceFill(['final_home_score' => null, 'final_away_score' => null])->save();

    foreach (range(3, 35) as $i) {
        auditGame($i, ($i % 12) * 1.1, 40 + ($i % 9));
    }

    expect(app(WeatherAudit::class)->run(Sport::Nfl, 1)['games'])->toBe(34);
});

it('refuses to measure a variable too thinly covered', function () {
    // Wind thins out before 2024 in the real archive. Measuring it on a
    // handful of games would produce a confident number from nothing.
    foreach (range(1, 40) as $i) {
        // Written without wind rather than written and then emptied.
        // weather_readings is append-only, and saveQuietly() only
        // slips past the model - the Postgres trigger still refuses.
        auditGame($i, $i > 5 ? null : 10.0, 40 + ($i % 7));
    }

    $result = app(WeatherAudit::class)->run(Sport::Nfl, 1);

    expect($result['variables'])->not->toHaveKey('wind_mph')
        ->and(implode(' ', $result['notes']))->toContain('too few to measure');
});

it('reads the lead it was asked for', function () {
    foreach (range(1, 35) as $i) {
        auditGame($i, ($i % 12) * 1.1, 40 + ($i % 9));
    }

    // Everything was written at lead 1, so lead 5 finds nothing at all.
    expect(app(WeatherAudit::class)->run(Sport::Nfl, 5)['games'])->toBe(0)
        ->and(app(WeatherAudit::class)->run(Sport::Nfl, 1)['games'])->toBe(35);
});

it('takes the median closing total across books', function () {
    // One stale marker should not decide what the market thought.
    $event = auditGame(1, 10.0, 44, closingTotal: 44.0);

    foreach ([['b2', 44.5], ['b3', 80.0]] as [$key, $line]) {
        $book = Book::firstOrCreate(['key' => $key], ['title' => $key, 'is_tracked' => true]);

        EventLineMarker::create([
            'event_id' => $event->id,
            'book_id' => $book->id,
            'market' => Market::Total->value,
            'side' => Side::Over->value,
            'line_close' => $line,
            'computed_at' => now(),
        ]);
    }

    foreach (range(2, 35) as $i) {
        auditGame($i, ($i % 12) * 1.1, 40 + ($i % 9), closingTotal: 44.0);
    }

    // 44, 44.5 and 80 median to 44.5, not to the 56.2 a mean would give.
    $residual = app(WeatherAudit::class)->run(Sport::Nfl, 1)['variables']['wind_mph']['residual'];

    expect($residual['n'])->toBe(35);
});
