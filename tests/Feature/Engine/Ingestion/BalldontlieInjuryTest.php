<?php

declare(strict_types=1);

use App\Enums\InjuryStatus;
use App\Enums\Sport;
use App\Models\InjuryReport;
use App\Models\Team;
use App\Models\UnmatchedName;
use App\Services\Ingestion\BalldontlieInjurySource;
use App\Services\Ingestion\InjurySources;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('edge.sources.balldontlie.key', 'a-test-key');
    config()->set('edge.sources.balldontlie.base_url', 'https://api.balldontlie.io/v1');
});

function nbaTeam(string $name, string $slug): Team
{
    return Team::create([
        'sport' => 'nba',
        'name' => $name,
        'display_name' => $name,
        'slug' => $slug,
    ]);
}

/** @param array<int, array<string, mixed>> $injuries */
function fakeBalldontlie(array $injuries, ?int $nextCursor = null, array $teams = []): void
{
    $teams = $teams ?: [
        ['id' => 7, 'full_name' => 'Memphis Grizzlies'],
        ['id' => 23, 'full_name' => 'Philadelphia 76ers'],
    ];

    Http::fake([
        'api.balldontlie.io/v1/teams*' => Http::response(['data' => $teams]),
        'api.balldontlie.io/v1/player_injuries*' => Http::response([
            'data' => $injuries,
            'meta' => ['next_cursor' => $nextCursor, 'per_page' => 100],
        ]),
    ]);
}

/** @param array<string, mixed> $overrides */
function injuryRow(array $overrides = []): array
{
    return array_replace_recursive([
        'player' => [
            'id' => 17896067,
            'first_name' => 'Santi',
            'last_name' => 'Aldama',
            'position' => 'F',
            'team_id' => 7,
        ],
        'return_date' => '2026-10-23',
        'description' => 'Aldama (knee) has been ruled out for Wednesday.',
        'status' => 'Day-To-Day',
    ], $overrides);
}

it('writes a report against the right team', function () {
    $grizzlies = nbaTeam('Memphis Grizzlies', 'memphis-grizzlies');
    fakeBalldontlie([injuryRow()]);

    $stats = app(BalldontlieInjurySource::class)->sync(Sport::Nba);

    $report = InjuryReport::sole();

    expect($stats['reports'])->toBe(1)
        ->and($report->team_id)->toBe($grizzlies->id)
        ->and($report->player_name)->toBe('Santi Aldama')
        ->and($report->player_external_id)->toBe('17896067')
        ->and($report->position)->toBe('F')
        ->and($report->source)->toBe('balldontlie');
});

it('maps the vocabulary this feed actually publishes', function (string $label, InjuryStatus $expected) {
    nbaTeam('Memphis Grizzlies', 'memphis-grizzlies');
    fakeBalldontlie([injuryRow(['status' => $label])]);

    app(BalldontlieInjurySource::class)->sync(Sport::Nba);

    expect(InjuryReport::sole()->status)->toBe($expected);
})->with([
    // Read off a live response, not guessed: these three are the whole
    // vocabulary the endpoint returned across a full page.
    ['Out', InjuryStatus::Out],
    ['Day-To-Day', InjuryStatus::Questionable],
    ['Questionable', InjuryStatus::Questionable],
]);

it('refuses to guess at a label it does not know', function () {
    // A wrong guess here reads as a confident statement about whether a
    // player is fit, and stake sizing takes it literally.
    nbaTeam('Memphis Grizzlies', 'memphis-grizzlies');
    fakeBalldontlie([injuryRow(['status' => 'Reconditioning'])]);

    $stats = app(BalldontlieInjurySource::class)->sync(Sport::Nba);

    expect(InjuryReport::sole()->status)->toBe(InjuryStatus::Unknown)
        ->and($stats['unknown_status'])->toBe(1)
        // The source's own wording survives, so the mapping can be fixed
        // later without re-fetching history.
        ->and(InjuryReport::sole()->detail)->toContain('source status: Reconditioning');
});

it('keeps the return date as a forecast rather than a report time', function () {
    nbaTeam('Memphis Grizzlies', 'memphis-grizzlies');
    $this->freezeTime();

    fakeBalldontlie([injuryRow()]);

    app(BalldontlieInjurySource::class)->sync(Sport::Nba);

    $report = InjuryReport::sole();

    expect($report->detail)->toContain('expected back 2026-10-23')
        // The feed states no report time, so the honest stamp is when we
        // asked - not a date in the future.
        ->and($report->reported_at->timestamp)->toBe(now()->timestamp);
});

it('skips a team it cannot resolve instead of picking the nearest', function () {
    // No Grizzlies row exists, so there is nothing to attach this to.
    fakeBalldontlie([injuryRow()]);

    $stats = app(BalldontlieInjurySource::class)->sync(Sport::Nba);

    expect($stats['unresolved'])->toBe(1)
        ->and(InjuryReport::count())->toBe(0)
        // Logged rather than dropped silently, so it can be worked through.
        ->and(UnmatchedName::count())->toBe(1);
});

it('skips a row with no player on it', function () {
    nbaTeam('Memphis Grizzlies', 'memphis-grizzlies');
    fakeBalldontlie([injuryRow(['player' => ['first_name' => '', 'last_name' => '']])]);

    expect(app(BalldontlieInjurySource::class)->sync(Sport::Nba)['reports'])->toBe(0);
});

it('walks every page of a cursor-paginated feed', function () {
    nbaTeam('Memphis Grizzlies', 'memphis-grizzlies');
    nbaTeam('Philadelphia 76ers', 'philadelphia-76ers');

    $page = 0;

    Http::fake([
        'api.balldontlie.io/v1/teams*' => Http::response(['data' => [
            ['id' => 7, 'full_name' => 'Memphis Grizzlies'],
            ['id' => 23, 'full_name' => 'Philadelphia 76ers'],
        ]]),
        'api.balldontlie.io/v1/player_injuries*' => function () use (&$page) {
            $page++;

            return Http::response([
                'data' => [injuryRow(['player' => ['id' => 100 + $page, 'team_id' => $page === 1 ? 7 : 23]])],
                'meta' => ['next_cursor' => $page < 3 ? $page * 1000 : null],
            ]);
        },
    ]);

    expect(app(BalldontlieInjurySource::class)->sync(Sport::Nba)['reports'])->toBe(3);
});

it('stops when the cursor stops moving', function () {
    // A metered paid API is the wrong place to spin: a cursor that repeats
    // itself would otherwise be an unbounded loop of billable requests.
    nbaTeam('Memphis Grizzlies', 'memphis-grizzlies');

    Http::fake([
        'api.balldontlie.io/v1/teams*' => Http::response(['data' => [['id' => 7, 'full_name' => 'Memphis Grizzlies']]]),
        'api.balldontlie.io/v1/player_injuries*' => Http::response([
            'data' => [injuryRow()],
            'meta' => ['next_cursor' => 500],
        ]),
    ]);

    // First page has no cursor set, second asks for 500 and is handed 500
    // again, which is where it gives up.
    expect(app(BalldontlieInjurySource::class)->sync(Sport::Nba)['reports'])->toBe(2);
});

it('writes a new row rather than updating an unchanged one', function () {
    // "Still day-to-day this morning" is a different fact from "was
    // day-to-day on Tuesday", and the table is append-only so it survives.
    nbaTeam('Memphis Grizzlies', 'memphis-grizzlies');
    fakeBalldontlie([injuryRow()]);

    app(BalldontlieInjurySource::class)->sync(Sport::Nba);
    app(BalldontlieInjurySource::class)->sync(Sport::Nba);

    expect(InjuryReport::count())->toBe(2);
});

it('does nothing for a sport this subscription does not cover', function () {
    // NFL and MLB are separate subscriptions on this account and answer
    // 401, so claiming to serve them would mean a failing job every four
    // hours for data that was never coming.
    Http::fake();

    expect(app(BalldontlieInjurySource::class)->isAvailable(Sport::Nfl))->toBeFalse()
        ->and(app(BalldontlieInjurySource::class)->sync(Sport::Nfl)['reports'])->toBe(0);

    Http::assertNothingSent();
});

it('does nothing without a key', function () {
    config()->set('edge.sources.balldontlie.key', null);
    Http::fake();

    expect(app(BalldontlieInjurySource::class)->isAvailable(Sport::Nba))->toBeFalse();

    Http::assertNothingSent();
});

it('is what the config chooses for the NBA', function () {
    // The config decides now. It used to declare a source that nothing
    // read, which is how it could say null for the NBA while ESPN was
    // quietly serving it.
    expect(app(InjurySources::class)->forSport(Sport::Nba))
        ->toBeInstanceOf(BalldontlieInjurySource::class);
});

it('leaves every other sport on ESPN, which is where they already were', function (Sport $sport) {
    expect(app(InjurySources::class)->forSport($sport)?->key())->toBe('espn');
})->with([Sport::Nfl, Sport::Mlb, Sport::Nhl, Sport::Ncaaf, Sport::Ncaab]);

it('says why rather than going quiet when a source cannot run', function () {
    config()->set('edge.sources.balldontlie.key', null);

    expect(app(InjurySources::class)->forSport(Sport::Nba))->toBeNull()
        ->and(app(InjurySources::class)->reasonFor(Sport::Nba))
        ->toContain('balldontlie')
        ->toContain('missing key');
});
