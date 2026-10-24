<?php

declare(strict_types=1);

use App\Enums\InjuryStatus;
use App\Enums\Sport;
use App\Exceptions\AppendOnlyViolation;
use App\Models\InjuryReport;
use App\Models\Team;
use App\Models\UnmatchedName;
use App\Services\Ingestion\EspnInjurySource;
use Illuminate\Support\Facades\Http;

/*
 * The payload shape here was captured from the live endpoint, including the
 * two details that are easy to get wrong from a guess: the athlete carries
 * no id field of its own - the player id is only inside the link href - and
 * each sport publishes its own status vocabulary.
 */

function injuryPayload(array $entries, string $team = 'Buffalo Bills', string $teamId = '2'): array
{
    return [
        'timestamp' => '2026-10-21T23:48:00Z',
        'status' => 'success',
        'injuries' => [[
            'id' => $teamId,
            'displayName' => $team,
            'injuries' => $entries,
        ]],
    ];
}

function injuryEntry(string $name, string $status, array $overrides = []): array
{
    return array_merge([
        'id' => '-1',
        'status' => $status,
        'date' => '2026-10-21T18:00:00Z',
        'shortComment' => 'limited in practice',
        'type' => ['description' => mb_strtolower($status)],
        'athlete' => [
            'displayName' => $name,
            'position' => ['abbreviation' => 'WR'],
            'links' => [[
                'href' => 'https://www.espn.com/nfl/player/_/id/4568506/'.str_replace(' ', '-', mb_strtolower($name)),
            ]],
        ],
    ], $overrides);
}

/*
 * Shaped like a seeded team, because that is what the resolver matches
 * against: the slug is the normalised full name, not the nickname.
 */
function bills(): Team
{
    return Team::firstOrCreate(
        ['sport' => 'nfl', 'slug' => 'buffalo-bills'],
        ['name' => 'Buffalo Bills', 'display_name' => 'Buffalo Bills', 'abbreviation' => 'BUF'],
    );
}

function fakeInjuries(array $payload): void
{
    Http::fake(['site.api.espn.com/*' => Http::response($payload)]);
}

it('writes a report for every entry', function () {
    bills();
    fakeInjuries(injuryPayload([
        injuryEntry('Keon Coleman', 'Questionable'),
        injuryEntry('Dalton Kincaid', 'Out'),
    ]));

    $stats = app(EspnInjurySource::class)->sync(Sport::Nfl);

    expect($stats['reports'])->toBe(2)
        ->and($stats['teams'])->toBe(1)
        ->and(InjuryReport::count())->toBe(2);
});

it('maps the status and keeps the source wording beside it', function () {
    bills();
    fakeInjuries(injuryPayload([injuryEntry('Keon Coleman', 'Questionable')]));

    app(EspnInjurySource::class)->sync(Sport::Nfl);

    $report = InjuryReport::sole();

    expect($report->status)->toBe(InjuryStatus::Questionable)
        ->and($report->detail)->toContain('source status: Questionable')
        ->and($report->player_name)->toBe('Keon Coleman')
        ->and($report->position)->toBe('WR');
});

it('pulls the player id out of the link, since the entry has none', function () {
    bills();
    fakeInjuries(injuryPayload([injuryEntry('Keon Coleman', 'Out')]));

    app(EspnInjurySource::class)->sync(Sport::Nfl);

    expect(InjuryReport::sole()->player_external_id)->toBe('4568506');
});

it('records an unrecognised status as unknown rather than guessing', function () {
    bills();
    fakeInjuries(injuryPayload([injuryEntry('Keon Coleman', 'Reconditioning')]));

    $stats = app(EspnInjurySource::class)->sync(Sport::Nfl);

    $report = InjuryReport::sole();

    expect($stats['unknown_status'])->toBe(1)
        ->and($report->status)->toBe(InjuryStatus::Unknown)
        // The wording survives, so the mapping can be fixed later without
        // re-fetching a history that no longer exists upstream.
        ->and($report->detail)->toContain('Reconditioning')
        ->and($report->status->availability())->toBeNull();
});

it('treats every injured list as unavailable', function () {
    bills();
    fakeInjuries(injuryPayload([
        injuryEntry('A Pitcher', '15-Day-IL'),
        injuryEntry('B Pitcher', '60-Day-IL'),
        injuryEntry('C Pitcher', '7-Day IL'),
    ]));

    app(EspnInjurySource::class)->sync(Sport::Nfl);

    expect(InjuryReport::where('status', InjuryStatus::Out->value)->count())->toBe(3);
});

it('skips a team it cannot resolve rather than guessing at the nearest', function () {
    // An injury attached to the wrong team is worse than a missing one: it
    // lands on the other side of a game.
    fakeInjuries(injuryPayload([injuryEntry('Someone', 'Out')], 'Not A Real Team', '999'));

    $stats = app(EspnInjurySource::class)->sync(Sport::Nfl);

    expect($stats['unresolved'])->toBe(1)
        ->and($stats['reports'])->toBe(0)
        ->and(InjuryReport::count())->toBe(0)
        ->and(UnmatchedName::count())->toBeGreaterThan(0);
});

it('ignores an entry with no player on it', function () {
    bills();
    fakeInjuries(injuryPayload([
        injuryEntry('Keon Coleman', 'Out'),
        ['status' => 'Out', 'athlete' => ['displayName' => '']],
    ]));

    expect(app(EspnInjurySource::class)->sync(Sport::Nfl)['reports'])->toBe(1);
});

it('stamps a report with its own date when it has one', function () {
    bills();
    fakeInjuries(injuryPayload([injuryEntry('Keon Coleman', 'Out')]));

    app(EspnInjurySource::class)->sync(Sport::Nfl);

    expect(InjuryReport::sole()->reported_at->toIso8601String())
        ->toBe('2026-10-21T18:00:00+00:00');
});

it('falls back to the response stamp when an entry carries no date', function () {
    bills();
    fakeInjuries(injuryPayload([
        injuryEntry('Keon Coleman', 'Out', ['date' => null]),
    ]));

    app(EspnInjurySource::class)->sync(Sport::Nfl);

    expect(InjuryReport::sole()->reported_at->toIso8601String())
        ->toBe('2026-10-21T23:48:00+00:00');
});

it('keeps an unchanged report as a new row rather than deduplicating it', function () {
    // "Still questionable on Sunday morning" is a different and more
    // useful fact than "was questionable on Thursday".
    bills();
    fakeInjuries(injuryPayload([injuryEntry('Keon Coleman', 'Questionable')]));

    app(EspnInjurySource::class)->sync(Sport::Nfl);
    app(EspnInjurySource::class)->sync(Sport::Nfl);

    expect(InjuryReport::count())->toBe(2);
});

it('never rewrites a report once stored', function () {
    bills();
    fakeInjuries(injuryPayload([injuryEntry('Keon Coleman', 'Questionable')]));

    app(EspnInjurySource::class)->sync(Sport::Nfl);

    $report = InjuryReport::sole();
    $report->status = InjuryStatus::Active->value;
    $report->save();
})->throws(AppendOnlyViolation::class);

it('reports itself unavailable for a sport with no path', function () {
    $source = app(EspnInjurySource::class);

    // Every sport in this build has an ESPN path, so the guard is checked
    // by removing one rather than by finding one that lacks it.
    config()->set('sports.nfl.espn_path', null);

    expect($source->isAvailable(Sport::Nfl))->toBeFalse()
        ->and($source->sync(Sport::Nfl)['reports'])->toBe(0);
});

it('does not call the endpoint for a sport it cannot serve', function () {
    Http::fake();
    config()->set('sports.nfl.espn_path', null);

    app(EspnInjurySource::class)->sync(Sport::Nfl);

    Http::assertNothingSent();
});

/*
 * The leakage rule for this table, which is stricter than it looks.
 */

it('hides a report that was fetched after kickoff, whatever date it carries', function () {
    // This is the trap. The feed publishes availability as it stands now,
    // so a report dated February that we pulled in October is October's
    // knowledge: a list of the injuries that turned out to last, which is
    // a fact from after a March game.
    $team = bills();
    $kickoff = now()->subMonths(3);

    InjuryReport::create([
        'sport' => 'nfl',
        'team_id' => $team->id,
        'player_name' => 'Long Term Case',
        'status' => InjuryStatus::Out->value,
        'source' => 'espn',
        // Reported long before the game...
        'reported_at' => $kickoff->copy()->subMonth(),
        // ...but only fetched today.
        'created_at' => now(),
    ]);

    expect(InjuryReport::query()->availableBefore($kickoff)->count())->toBe(0)
        // And it is still in the table: the row is honest, the filter is
        // what keeps it out of a model.
        ->and(InjuryReport::count())->toBe(1);
});

it('shows a report that was both reported and fetched before kickoff', function () {
    $team = bills();
    $kickoff = now();

    InjuryReport::create([
        'sport' => 'nfl',
        'team_id' => $team->id,
        'player_name' => 'Knowable Case',
        'status' => InjuryStatus::Questionable->value,
        'source' => 'espn',
        'reported_at' => $kickoff->copy()->subDays(2),
        'created_at' => $kickoff->copy()->subDays(2),
    ]);

    expect(InjuryReport::query()->availableBefore($kickoff)->count())->toBe(1);
});

it('hides a report reported after kickoff even if fetched before it', function () {
    $team = bills();
    $kickoff = now()->subDay();

    InjuryReport::create([
        'sport' => 'nfl',
        'team_id' => $team->id,
        'player_name' => 'Box Score Case',
        'status' => InjuryStatus::Out->value,
        'source' => 'espn',
        'reported_at' => $kickoff->copy()->addHours(2),
        'created_at' => $kickoff->copy()->subHour(),
    ]);

    expect(InjuryReport::query()->availableBefore($kickoff)->count())->toBe(0);
});

it('narrows to the players who are definitely not playing', function () {
    $team = bills();

    foreach ([InjuryStatus::Out, InjuryStatus::Questionable, InjuryStatus::Active] as $status) {
        InjuryReport::create([
            'sport' => 'nfl',
            'team_id' => $team->id,
            'player_name' => 'Player '.$status->value,
            'status' => $status->value,
            'source' => 'espn',
            'reported_at' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);
    }

    expect(InjuryReport::query()->unavailable()->count())->toBe(1);
});
