<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\InjuryStatus;
use App\Enums\Sport;
use App\Exceptions\LeakageViolation;
use App\Models\Event;
use App\Models\EventExternalId;
use App\Models\FeatureSet;
use App\Models\InjuryReport;
use App\Models\ProbableStarter;
use App\Models\Team;
use App\Models\WeatherReading;
use App\Services\Ingestion\MlbProbablePitcherSource;
use Illuminate\Support\Facades\Http;

/**
 * The leakage test. A model trained on information that did not exist before
 * kickoff will look excellent and be worthless. This runs in CI, and the
 * build fails if anything can write a feature vector that reaches forward in
 * time, or if any stored vector already does.
 */
function makeEvent(): Event
{
    $home = Team::create(['sport' => Sport::Nfl->value, 'name' => 'Home', 'display_name' => 'Home Team', 'slug' => 'home-team']);
    $away = Team::create(['sport' => Sport::Nfl->value, 'name' => 'Away', 'display_name' => 'Away Team', 'slug' => 'away-team']);

    return Event::create([
        'sport' => Sport::Nfl->value,
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'starts_at' => now()->addDay(),
        'status' => EventStatus::Scheduled->value,
    ]);
}

it('accepts a feature set built entirely from data that predates kickoff', function () {
    $event = makeEvent();

    $set = FeatureSet::create([
        'event_id' => $event->id,
        'schema_version' => 1,
        'payload' => ['rest_days' => 7],
        'as_of' => now(),
        'max_source_at' => $event->starts_at->copy()->subHour(),
        'event_starts_at' => $event->starts_at,
        'built_at' => now(),
        'created_at' => now(),
    ]);

    expect($set->leaksFuture())->toBeFalse();
});

it('refuses a feature set whose newest source timestamp is after kickoff', function () {
    $event = makeEvent();

    expect(fn () => FeatureSet::create([
        'event_id' => $event->id,
        'schema_version' => 1,
        'payload' => ['final_score_known' => true],
        'as_of' => now(),
        'max_source_at' => $event->starts_at->copy()->addMinute(),
        'event_starts_at' => $event->starts_at,
        'built_at' => now(),
        'created_at' => now(),
    ]))->toThrow(LeakageViolation::class);

    expect(FeatureSet::count())->toBe(0);
});

it('finds no stored feature set that reaches past kickoff', function () {
    $leaking = FeatureSet::query()
        ->whereColumn('max_source_at', '>', 'event_starts_at')
        ->count();

    expect($leaking)->toBe(0);
});

/*
 * The point-in-time tables.
 *
 * No model reads these yet, which is exactly why they are worth guarding
 * now: the moment one does, a row stamped after kickoff becomes a fact
 * from the future wearing a pre-game label. The feature-set guard above
 * cannot see them, because it only knows about feature vectors.
 */

it('writes no probable starter for a game that has already started', function () {
    // The MLB schedule window opens today, so it carries this morning's
    // games too. A "probable" pitcher recorded after first pitch is a
    // box-score fact in a pre-game table.
    $home = Team::firstOrCreate(
        ['sport' => 'mlb', 'slug' => 'leak-home'],
        ['name' => 'Leak Home', 'display_name' => 'Leak Home'],
    );
    $away = Team::firstOrCreate(
        ['sport' => 'mlb', 'slug' => 'leak-away'],
        ['name' => 'Leak Away', 'display_name' => 'Leak Away'],
    );

    foreach ([['started', -3], ['upcoming', 6]] as [$label, $hours]) {
        $event = Event::create([
            'sport' => 'mlb',
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'starts_at' => now()->addHours($hours),
            'status' => 'scheduled',
        ]);

        EventExternalId::create([
            'event_id' => $event->id,
            'source' => 'mlb_statsapi',
            'external_id' => $label,
        ]);
    }

    Http::fake([
        'statsapi.mlb.com/api/v1/schedule*' => Http::response([
            'dates' => [['games' => [
                ['gamePk' => 'started', 'teams' => [
                    'home' => ['probablePitcher' => ['id' => 1, 'fullName' => 'Too Late']],
                    'away' => [],
                ]],
                ['gamePk' => 'upcoming', 'teams' => [
                    'home' => ['probablePitcher' => ['id' => 2, 'fullName' => 'In Time']],
                    'away' => [],
                ]],
            ]]],
        ]),
        'statsapi.mlb.com/api/v1/people*' => Http::response(['people' => []]),
    ]);

    $stats = app(MlbProbablePitcherSource::class)->sync();

    expect($stats['already_started'])->toBe(1)
        ->and($stats['starters'])->toBe(1)
        ->and(ProbableStarter::sole()->player_name)->toBe('In Time');
});

it('holds no stored starter that was reported after its own kickoff', function () {
    $leaking = ProbableStarter::query()
        ->join('events', 'events.id', '=', 'probable_starters.event_id')
        ->whereColumn('probable_starters.reported_at', '>', 'events.starts_at')
        ->count();

    expect($leaking)->toBe(0);
});

it('offers no injury report that was fetched after kickoff', function () {
    // reported_at alone is not enough: the feed publishes only what is
    // true now, so a February-dated row pulled in October is October's
    // knowledge. availableBefore insists on both timestamps.
    $event = makeEvent();
    $team = Team::where('sport', 'nfl')->first();

    InjuryReport::create([
        'sport' => 'nfl',
        'team_id' => $team->id,
        'player_name' => 'Hindsight',
        'status' => InjuryStatus::Out->value,
        'source' => 'espn',
        'reported_at' => $event->starts_at->copy()->subMonth(),
        'created_at' => $event->starts_at->copy()->addDay(),
    ]);

    expect(InjuryReport::query()->availableBefore($event->starts_at)->count())->toBe(0);
});

it('offers no weather reading that cannot say how far ahead it was made', function () {
    // A reading with no lead is one nothing can place relative to kickoff,
    // so it must never reach a model through atLeadDays.
    $event = makeEvent();

    WeatherReading::create([
        'event_id' => $event->id,
        'is_forecast' => true,
        'valid_at' => $event->starts_at,
        'temp_f' => 50.0,
        'source' => 'open_meteo',
        'fetched_at' => now(),
    ]);

    expect(WeatherReading::query()->atLeadDays(0)->count())->toBe(0);
});
