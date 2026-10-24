<?php

declare(strict_types=1);

use App\Enums\JobStatus;
use App\Models\JobRun;
use App\Models\LiveFeedProbe;
use Illuminate\Support\Facades\Http;

it('refuses a sport it does not know', function () {
    $this->artisan('edge:live:probe quidditch')->assertFailed();
});

it('watches a game and writes down what it saw', function () {
    Http::fake([
        'site.api.espn.com/*' => Http::response(['events' => []]),
        'api-web.nhle.com/*' => Http::response(nhlScore(3, 2)),
    ]);

    // One tick: the next sleep would run past the deadline, so it stops.
    $this->artisan('edge:live:probe nhl --minutes=1 --every=60 --no-odds')
        ->assertSuccessful()
        ->expectsOutputToContain('Feed audit');

    expect(LiveFeedProbe::query()->gameState()->count())->toBe(1);
});

it('records the probe as a job run like everything else here', function () {
    Http::fake([
        'site.api.espn.com/*' => Http::response(['events' => []]),
        'api-web.nhle.com/*' => Http::response(nhlScore(3, 2)),
    ]);

    $this->artisan('edge:live:probe nhl --minutes=1 --every=60 --no-odds')->assertSuccessful();

    $run = JobRun::query()->where('job_key', 'live:probe')->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(JobStatus::Success)
        ->and($run->context['session'])->not->toBeNull();
});

it('says what it cannot measure without a price feed', function () {
    config()->set('edge.odds_api.key', null);

    Http::fake([
        'site.api.espn.com/*' => Http::response(['events' => []]),
        'api-web.nhle.com/*' => Http::response(nhlScore(3, 2)),
    ]);

    $this->artisan('edge:live:probe nhl --minutes=1 --every=60')
        ->assertSuccessful()
        ->expectsOutputToContain('No ODDS_API_KEY');
});

it('warns when a sport has only one feed to check', function () {
    Http::fake(['site.api.espn.com/*' => Http::response(['events' => []])]);

    $this->artisan('edge:live:probe nba --minutes=1 --every=60 --no-odds')
        ->assertSuccessful()
        ->expectsOutputToContain('Only one state feed');
});
