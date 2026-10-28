<?php

declare(strict_types=1);

use App\Models\AiUsage;
use App\Models\ReadView;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VigilRead;
use App\Services\Ai\ReadQuota;
use App\Services\Ai\ReadWriter;

/*
|--------------------------------------------------------------------------
| §4: the free plan gets one Vigil Read a day.
| §6: customer-triggered Reads are rate limited per tier.
|--------------------------------------------------------------------------
|
| Two different limits. Viewing is what the free plan *is* - board Reads
| are generated once and shared, so an extra view costs nothing.
| Generating costs money on every call, which is why that ceiling is much
| higher but still a ceiling.
|
*/

function quotaUser(?string $tier = null): User
{
    $user = User::forceCreate([
        'name' => 'Sam',
        'email' => 'sam'.fake()->unique()->randomNumber(6).'@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
    ]);

    if ($tier !== null) {
        Subscription::create([
            'user_id' => $user->id, 'tier' => $tier, 'status' => 'active',
            'current_period_end' => now()->addMonth(), 'source' => 'test',
        ]);
    }

    return $user->refresh();
}

/**
 * Somebody with a trial running.
 *
 * The capped daily allowance used to belong to the free tier. It is the
 * trial's now - that is where a number between zero and unlimited
 * actually lives - so the tests that exercise a cap exercise this.
 */
function trialQuotaUser(): User
{
    $user = quotaUser();

    $user->forceFill([
        'trial_started_at' => now(),
        'trial_expires_at' => now()->addHours((int) config('plans.trial_hours')),
        'trial_used_at' => now(),
    ])->save();

    return $user->fresh();
}

function aRead(int $subjectId): VigilRead
{
    return VigilRead::create([
        'subject_type' => 'board_entry',
        'subject_id' => $subjectId,
        'prompt_version' => (int) config('ai.prompt_version'),
        'body' => 'An explanation of the figures above.',
        'source' => 'fallback',
        'fact_sheet' => [],
        'created_at' => now(),
    ]);
}

it('gives an account with no trial and no plan nothing at all', function () {
    // Registration creates no subscription and starting the trial is an
    // explicit act. This is the state between the two, and it is the
    // one that used to silently hand out a free Read a day.
    $user = quotaUser();
    $quota = app(ReadQuota::class);

    expect($quota->viewAllowance($user))->toBe(0)
        ->and($quota->viewsRemaining($user))->toBe(0)
        ->and($quota->claim($user, aRead(1)))->toBeFalse();
});

it('gives a trial five reads a day and says so', function () {
    $user = trialQuotaUser();
    $quota = app(ReadQuota::class);

    expect($quota->viewAllowance($user))->toBe(5)
        ->and($quota->viewsRemaining($user))->toBe(5);

    foreach (range(1, 5) as $n) {
        expect($quota->claim($user, aRead($n)))->toBeTrue("read {$n} was refused");
    }

    // The sixth, a different Read, is refused.
    expect($quota->viewsRemaining($user))->toBe(0)
        ->and($quota->claim($user, aRead(6)))->toBeFalse();
});

it('does not charge a customer twice for reading the same thing twice', function () {
    $user = trialQuotaUser();
    $quota = app(ReadQuota::class);
    $read = aRead(1);

    expect($quota->claim($user, $read))->toBeTrue()
        ->and($quota->claim($user, $read))->toBeTrue()
        ->and($quota->claim($user, $read))->toBeTrue();

    // A counter could not tell this from reading three Reads, which is
    // why the key is (user, read, day) and not a number.
    expect(ReadView::count())->toBe(1)
        ->and($quota->viewsRemaining($user))->toBe(4);
});

it('lets yesterdays allowance expire without taking away what was read', function () {
    $user = trialQuotaUser();
    $quota = app(ReadQuota::class);
    $read = aRead(1);

    $quota->claim($user, $read);

    $this->travel(1)->day();

    expect($quota->viewsRemaining($user))->toBe(5)
        // And the one already opened is still free to reopen.
        ->and($quota->claim($user, $read))->toBeTrue();
});

it('does not limit a subscriber', function (string $tier) {
    $user = quotaUser($tier);
    $quota = app(ReadQuota::class);

    expect($quota->viewAllowance($user))->toBeNull()
        ->and($quota->viewsRemaining($user))->toBeNull();

    foreach (range(1, 12) as $i) {
        expect($quota->claim($user, aRead($i)))->toBeTrue();
    }

    // Nothing recorded, because nothing is being counted.
    expect(ReadView::count())->toBe(0);
})->with(['daily', 'pro']);

it('shows nothing to a signed-out visitor', function () {
    // The marketing sample board does not carry Reads, and this is why.
    expect(app(ReadQuota::class)->claim(null, aRead(1)))->toBeFalse();
});

it('spends a trial read on the top pick rather than whichever row came first', function () {
    config()->set('ai.enabled', false);

    /*
     * One read for the length of this test, because the question is
     * which pick an allowance is spent on and an allowance larger than
     * the board answers nothing. The real number is five.
     */
    config()->set('plans.tiers.trial.limits.reads_per_day', 1);

    $user = trialQuotaUser();

    // Two proven picks, the stronger one first by edge.
    $strong = entryFor(['id' => 1, 'recommendation' => 'bet', 'market_verdict' => 'proven', 'edge_at_price' => 0.09]);
    $weak = entryFor(['id' => 2, 'recommendation' => 'bet', 'market_verdict' => 'proven', 'edge_at_price' => 0.01]);

    app(ReadWriter::class)->forBoardEntry($strong);
    app(ReadWriter::class)->forBoardEntry($weak);

    $this->actingAs($user)->get(route('today'))->assertOk();

    $seen = ReadView::with('read')->sole();

    expect($seen->read->subject_id)->toBe($strong->id);
});

it('tells the customer the allowance is spent rather than showing a gap', function () {
    config()->set('ai.enabled', false);

    $user = trialQuotaUser();
    $entry = entryFor(['recommendation' => 'bet', 'market_verdict' => 'proven']);

    app(ReadWriter::class)->forBoardEntry($entry);

    // Spend the whole day's allowance on something else first.
    foreach (range(990, 994) as $n) {
        app(ReadQuota::class)->claim($user, aRead($n));
    }

    $this->actingAs($user)->get(route('today'))
        ->assertOk()
        // A customer who cannot tell a used-up allowance from a missing
        // explanation will reasonably conclude the product is broken.
        ->assertSee('You have used today')
        ->assertSee(route('upgrade', ['feature' => 'card.daily']), escape: false);
});

it('shows the read to a subscriber on the dashboard', function () {
    config()->set('ai.enabled', false);

    $entry = entryFor(['recommendation' => 'bet', 'market_verdict' => 'proven']);
    $read = app(ReadWriter::class)->forBoardEntry($entry);

    $this->actingAs(quotaUser('pro'))->get(route('today'))
        ->assertOk()
        ->assertSee($read->body)
        // Labelled, because a customer is owed the difference between
        // the model's words and the template's.
        ->assertSee('Plain');
});

/* ----------------------------------------------------------------------
 | Generating
 ---------------------------------------------------------------------- */

it('rate limits the reads a customer can trigger, by tier', function (?string $tier, int $expected) {
    expect(app(ReadQuota::class)->generateAllowance(quotaUser($tier)))->toBe($expected);
})->with([
    [null, 1],
    ['daily', 50],
    ['pro', 200],
]);

it('counts generations from the row that proves money was spent', function () {
    $user = quotaUser();
    $quota = app(ReadQuota::class);

    expect($quota->mayGenerate($user))->toBeTrue();

    AiUsage::create([
        'purpose' => 'read.user_bet',
        'user_id' => $user->id,
        'model' => 'claude-sonnet-5-5',
        'input_tokens' => 900, 'output_tokens' => 110, 'cache_read_tokens' => 0,
        'cost_millicents' => 10, 'accepted' => true, 'duration_ms' => 12,
        'created_at' => now(),
    ]);

    // A limit enforced against a counter that can be cleared is a limit
    // that can be cleared, so it is counted from ai_usage.
    expect($quota->mayGenerate($user))->toBeFalse()
        ->and($quota->generationsRemaining($user))->toBe(0);
});

it('does not spend a customers allowance on the nightly board backfill', function () {
    config()->set('ai.enabled', true);
    config()->set('ai.api_key', 'sk-ant-test');
    claudeReturns(paddedRead('The market is not yet proven. The model puts this at 58%.'));

    $user = quotaUser();

    // Generated for everybody, attributed to nobody.
    app(ReadWriter::class)->forBoardEntry(entryFor());

    expect(AiUsage::sole()->user_id)->toBeNull()
        ->and(app(ReadQuota::class)->mayGenerate($user))->toBeTrue();
});

it('attributes a read generated for one customer to that customer', function () {
    config()->set('ai.enabled', true);
    config()->set('ai.api_key', 'sk-ant-test');
    claudeReturns(paddedRead('The market is not yet proven. The model puts this at 58%.'));

    $user = quotaUser();

    app(ReadWriter::class)->forBoardEntry(entryFor(), for: $user);

    expect(AiUsage::sole()->user_id)->toBe($user->id)
        ->and(app(ReadQuota::class)->mayGenerate($user))->toBeFalse();
});

it('never lets a signed-out visitor trigger a read', function () {
    expect(app(ReadQuota::class)->mayGenerate(null))->toBeFalse();
});
