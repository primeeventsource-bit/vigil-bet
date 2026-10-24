<?php

declare(strict_types=1);

use App\Models\ApiRequest;
use App\Services\Ops\ApiMeter;

/*
 * The budget guard, and the plan size it guards against.
 *
 * The Odds API reports used and remaining on every response, and the two
 * add up to the plan. Trusting the configured number instead breaks in
 * both directions, and one of those directions is silent.
 */

function recordedCall(int $used, int $remaining, string $provider = 'the_odds_api'): ApiRequest
{
    return ApiRequest::create([
        'provider' => $provider,
        'endpoint' => 'sports/americanfootball_nfl/odds',
        'method' => 'GET',
        'http_status' => 200,
        'quota_used' => $used,
        'quota_remaining' => $remaining,
        'requested_at' => now(),
    ]);
}

it('reads the plan size off the provider rather than the config', function () {
    recordedCall(used: 20, remaining: 480);

    expect(app(ApiMeter::class)->observedQuota('the_odds_api'))->toBe(500);
});

it('knows nothing about the plan before a call has been made', function () {
    // Honest: until the key has been used, only the person holding it
    // knows which plan it is on.
    expect(app(ApiMeter::class)->observedQuota('the_odds_api'))->toBeNull();
});

it('keeps pulling on a free tier that the config overstates', function () {
    // The bug this replaced. Real plan 500, config still at its 20,000
    // default: the reserve came out at 2,000, so "remaining > reserve" was
    // false on the very first check and every pull was refused while 480
    // requests sat unused. Nothing on screen said why.
    recordedCall(used: 20, remaining: 480);

    expect(app(ApiMeter::class)->hasBudget('the_odds_api', monthlyQuota: 20000, reservePct: 10))
        ->toBeTrue();
});

it('still stops at the reserve on that free tier', function () {
    // 10% of the real 500 is 50. Forty left is under it.
    recordedCall(used: 460, remaining: 40);

    expect(app(ApiMeter::class)->hasBudget('the_odds_api', monthlyQuota: 20000, reservePct: 10))
        ->toBeFalse();
});

it('reserves against the real plan when the config understates it', function () {
    // Config 500, real plan 20,000. The reserve is 2,000, not 50.
    recordedCall(used: 18500, remaining: 1500);

    expect(app(ApiMeter::class)->hasBudget('the_odds_api', monthlyQuota: 500, reservePct: 10))
        ->toBeFalse();

    recordedCall(used: 15000, remaining: 5000);

    expect(app(ApiMeter::class)->hasBudget('the_odds_api', monthlyQuota: 500, reservePct: 10))
        ->toBeTrue();
});

it('falls back to the configured quota and local counting before any call', function () {
    // No header has ever been seen, so the config is all there is and
    // requests are counted locally.
    $meter = app(ApiMeter::class);

    expect($meter->hasBudget('the_odds_api', monthlyQuota: 100, reservePct: 10))->toBeTrue();

    foreach (range(1, 90) as $ignored) {
        ApiRequest::create([
            'provider' => 'the_odds_api',
            'endpoint' => 'sports/x/odds',
            'method' => 'GET',
            'http_status' => 200,
            'requested_at' => now(),
        ]);
    }

    expect($meter->hasBudget('the_odds_api', monthlyQuota: 100, reservePct: 10))->toBeFalse();
});

it('takes the newest report, since a plan can change', function () {
    recordedCall(used: 20, remaining: 480);

    $this->travel(1)->hour();

    recordedCall(used: 100, remaining: 19900);

    expect(app(ApiMeter::class)->observedQuota('the_odds_api'))->toBe(20000);
});

it('ignores a call that reported no quota headers at all', function () {
    recordedCall(used: 20, remaining: 480);

    ApiRequest::create([
        'provider' => 'the_odds_api',
        'endpoint' => 'sports/x/odds',
        'method' => 'GET',
        'http_status' => 500,
        'requested_at' => now()->addHour(),
    ]);

    // A failed call tells us nothing about the plan, so the last good
    // reading stands.
    expect(app(ApiMeter::class)->observedQuota('the_odds_api'))->toBe(500);
});
