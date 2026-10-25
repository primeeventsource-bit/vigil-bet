<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The season nobody backfilled
|--------------------------------------------------------------------------
|
| `edge:backfill <sport> 5` counted back from the current year starting at
| one, so it loaded the five seasons before this one and never this one.
| The live sync fills in upcoming games and last night's results, so the
| current season arrived from whenever the app started watching and no
| earlier.
|
| Measured on 25 October 2026, that left: 14 finished NFL games in a
| season eight weeks old, four MLB games in a season that had just ended,
| and 587 of 2,477 college football games - while the models predicting
| those sports trained on whatever history existed and the research brief
| looked for recent form in it.
|
| The current season is not a past season, so it is not counted against
| that argument. It is loaded every time, because it is the one being
| predicted.
|
*/

use App\Enums\Sport;
use App\Services\Ingestion\SourceRegistry;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    // Keyed sources report themselves unavailable and are skipped, which
    // would make these assertions pass by describing nothing.
    config()->set('edge.sources.cfbd.key', 'test-key');

    // The command queues; these tests are about what it decides to
    // queue. Without this the jobs run inline and reach the real feeds.
    Bus::fake();
});

it('loads the current season, which is the one being predicted', function (): void {
    $this->artisan('edge:backfill ncaaf 2')
        ->expectsOutputToContain('queued season '.now()->year)
        ->assertSuccessful();
});

it('still loads the past seasons that were asked for', function (): void {
    $year = (int) now()->year;

    $this->artisan('edge:backfill ncaaf 2')
        ->expectsOutputToContain('queued season '.($year - 1))
        ->expectsOutputToContain('queued season '.($year - 2))
        ->assertSuccessful();
});

it('does not count the current season against the seasons asked for', function (): void {
    /*
     * Two past seasons plus this one. Counting the current season as one
     * of the two would silently drop a year of history from every
     * backfill that had been run before.
     */
    $year = (int) now()->year;

    $this->artisan('edge:backfill ncaaf 2')
        ->expectsOutputToContain('3 backfill jobs')
        ->assertSuccessful();

    expect($year)->toBeGreaterThan(2000);
});

it('skips a sport whose schedule source cannot answer, rather than queueing work that cannot run', function (): void {
    config()->set('edge.sources.cfbd.key', null);

    $this->artisan('edge:backfill ncaaf 2')
        ->expectsOutputToContain('Skipping')
        ->assertSuccessful();
});

it('names a real schedule source for every sport it would back fill', function (): void {
    /*
     * Guards the tests above: a sport with no configured source would
     * take the skip path and prove nothing about seasons.
     */
    $registry = app(SourceRegistry::class);

    foreach (Sport::cases() as $sport) {
        expect($registry->forRole($sport, 'schedules'))
            ->not->toBeNull("{$sport->value} has no schedules source");
    }
});
