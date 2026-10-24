<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\PublishedPick;
use App\Models\ShadowPick;
use App\Models\ShadowResult;
use App\Services\Record\ShadowRecorder;

/*
|--------------------------------------------------------------------------
| Emptying the shadow record
|--------------------------------------------------------------------------
|
| A destructive command on an append-only table, so the tests are about
| what it refuses rather than what it does.
|
*/

it('refuses without force', function () {
    shadowCandidate();
    app(ShadowRecorder::class)->record();

    $this->artisan('vigil:shadow-reset')->assertExitCode(1);

    expect(ShadowPick::count())->toBe(1);
});

it('refuses once anything has been settled', function () {
    /*
     * The important one. A shadow pick is only writable before kickoff,
     * so a graded row can never be reproduced - dropping one destroys a
     * measurement for good. The whole point of allowing this command at
     * all was that nothing had been measured yet.
     */
    $bet = shadowCandidate(hoursToKickoff: 2);
    app(ShadowRecorder::class)->record();

    $this->travel(4)->hours();

    Event::query()->whereKey($bet->event_id)->update([
        'status' => 'final',
        'final_home_score' => 27,
        'final_away_score' => 20,
    ]);

    app(ShadowRecorder::class)->settle();

    expect(ShadowResult::count())->toBe(1);

    $this->artisan('vigil:shadow-reset --force')->assertExitCode(1);

    expect(ShadowPick::count())->toBe(1)
        ->and(ShadowResult::count())->toBe(1);
});

it('empties the record when forced and nothing is settled', function () {
    shadowCandidate();
    app(ShadowRecorder::class)->record();

    expect(ShadowPick::count())->toBe(1);

    $this->artisan('vigil:shadow-reset --force')->assertExitCode(0);

    expect(ShadowPick::count())->toBe(0);
});

it('never touches the public record', function () {
    // Written out rather than borrowed from PublicRecordPageTest, which
    // would make this file depend on that one loading first. There is no
    // factory for this model: nothing in the app creates a published
    // pick except the publisher.
    $pick = PublishedPick::create([
        'edge_lab_event_id' => 9001,
        'sport' => 'nfl',
        'market' => 'h2h',
        'side' => 'home',
        'selection' => 'Buffalo Bills',
        'matchup' => 'Miami Dolphins at Buffalo Bills',
        'price_american' => -110,
        'book' => 'draftkings',
        'book_title' => 'DraftKings',
        'model_prob' => 0.58,
        'fair_prob' => 0.55,
        'edge_at_price' => 0.03,
        'stake_fraction' => 0.012,
        'model_version' => 4,
        'kickoff_at' => now()->addHours(3),
        'published_at' => now()->subMinute(),
        'created_at' => now()->subMinute(),
    ]);

    shadowCandidate();
    app(ShadowRecorder::class)->record();

    $this->artisan('vigil:shadow-reset --force')->assertExitCode(0);

    // The one table this command must not be able to reach.
    expect(PublishedPick::count())->toBe(1)
        ->and(PublishedPick::find($pick->id))->not->toBeNull();
});
