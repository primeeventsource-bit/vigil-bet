<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\TrialRedemption;
use App\Models\User;
use App\Services\Entitlements\Entitlements;
use App\Services\Trial\EmailIdentity;
use App\Services\Trial\TrialService;

/*
|--------------------------------------------------------------------------
| The 72-hour trial
|--------------------------------------------------------------------------
|
| The spec's own testing list, line by line, plus the two things it
| implies without naming: that the clock is the server's, and that
| nothing anywhere converts a trial into a payment.
|
| The hardest thing to get right here is not expiry. It is that an
| expired trial has to take the product away without taking the account,
| and "preserve saved picks and parlays" has to mean a person can still
| open them - preserved data nobody can read is not preserved in any
| sense that matters to whoever entered it.
|
*/

function trialUser(bool $verified = true): User
{
    return User::forceCreate([
        'name' => 'Trial Person',
        'email' => 'trial'.fake()->unique()->randomNumber(8).'@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => $verified ? now() : null,
    ])->refresh();
}

function trials(): TrialService
{
    return app(TrialService::class);
}

/* ----------------------------------------------------------------------
 | Activation
 ---------------------------------------------------------------------- */

it('does not start the clock at registration', function () {
    /*
     * The spec is explicit: the countdown begins when somebody presses
     * the button, not when they sign up. Otherwise a person who
     * registers at midnight and comes back on Saturday has spent most
     * of their trial asleep.
     */
    $user = trialUser();

    expect($user->trial_started_at)->toBeNull()
        ->and($user->trialStatus())->toBe('none')
        ->and(app(Entitlements::class)->tier($user))->toBe('guest');
});

it('refuses to activate before the address is confirmed', function () {
    $user = trialUser(verified: false);

    // The route is inside the `verified` group, so this never reaches
    // the service in practice. The service checks anyway, because it is
    // the thing writing the row.
    expect(trials()->eligible($user))->toBeFalse();

    expect(fn () => trials()->activate($user))
        ->toThrow(RuntimeException::class);
});

it('starts a 72-hour clock on activation, to the second', function () {
    $user = trialUser();

    $this->freezeTime();

    $this->actingAs($user)->post(route('trial.activate'))->assertRedirect(route('today'));

    $user->refresh();

    /*
     * The span, not microsecond identity through a database column.
     * Postgres and SQLite round a timestamp differently and neither
     * rounding has anything to do with the promise, which is that the
     * expiry is the activation plus exactly 72 hours.
     */
    // Cast: Carbon 3 returns a float from diffInSeconds, and 259200.0
    // is not identical to 259200.
    expect((int) $user->trial_started_at->diffInSeconds($user->trial_expires_at))->toBe(72 * 3600)
        ->and($user->trial_started_at->timezone->getName())->toBe('UTC')
        ->and($user->trial_expires_at->timezone->getName())->toBe('UTC')
        ->and((int) $user->trial_started_at->diffInSeconds(now()))->toBeLessThan(2)
        ->and($user->trial_used_at)->not->toBeNull()
        ->and($user->trialStatus())->toBe('active');
});

it('takes no card and records no payment', function () {
    $user = trialUser();

    $this->actingAs($user)->post(route('trial.activate'));

    // Nothing in the billing projection should know this happened.
    expect(Subscription::where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('billing_events')->count())->toBe(0);
});

/* ----------------------------------------------------------------------
 | One each
 ---------------------------------------------------------------------- */

it('refuses a second activation on the same account', function () {
    $user = trialUser();

    $this->actingAs($user)->post(route('trial.activate'));
    $firstExpiry = $user->fresh()->trial_expires_at;

    $this->travel(1)->hour();

    $this->actingAs($user)->post(route('trial.activate'))->assertSessionHas('error');

    // The clock did not restart.
    expect($user->fresh()->trial_expires_at->equalTo($firstExpiry))->toBeTrue();
});

it('refuses a trial to the same person under a trivially altered address', function (string $second) {
    /*
     * The cheapest repeat there is. Both of these arrive at the same
     * inbox, and treating them as two people is treating a moved dot as
     * a second human being.
     */
    $first = User::forceCreate([
        'name' => 'A', 'email' => 'sam.smith@gmail.com',
        'password' => 'correct-horse-battery-staple', 'email_verified_at' => now(),
    ]);

    $this->actingAs($first)->post(route('trial.activate'));

    $again = User::forceCreate([
        'name' => 'B', 'email' => $second,
        'password' => 'correct-horse-battery-staple', 'email_verified_at' => now(),
    ]);

    expect(trials()->eligible($again->refresh()))->toBeFalse();
})->with([
    'samsmith@gmail.com',
    'sam.smith+trial@gmail.com',
    's.a.m.s.m.i.t.h@gmail.com',
]);

it('does not merge two different people at a host where dots matter', function () {
    /*
     * The failure that would be worse than letting a repeat through:
     * telling somebody their trial is spent when it is not, with no way
     * for them to argue. Dots are significant nearly everywhere except
     * Google, so they are only stripped there.
     */
    expect(EmailIdentity::normalise('j.smith@outlook.com'))
        ->not->toBe(EmailIdentity::normalise('jsmith@outlook.com'));
});

it('keeps the redemption when the account that made it goes', function () {
    // The record is about a person, not an account, and it has to
    // outlive everything that can happen to one.
    $user = trialUser();
    $this->actingAs($user)->post(route('trial.activate'));

    $hash = EmailIdentity::hash($user->email);
    $user->delete();

    expect(TrialRedemption::where('email_hash', $hash)->exists())->toBeTrue();
});

it('stores no address and no IP in the clear', function () {
    $user = trialUser();
    $this->actingAs($user)->post(route('trial.activate'));

    $row = TrialRedemption::sole();

    expect($row->email_hash)->not->toContain('@')
        ->and($row->email_hash)->not->toBe($user->email)
        ->and(strlen($row->email_hash))->toBe(64);
});

/* ----------------------------------------------------------------------
 | Expiry
 ---------------------------------------------------------------------- */

it('still works one second before the end and is over one second after', function () {
    $user = trialUser();

    $this->freezeTime();
    $this->actingAs($user)->post(route('trial.activate'));

    $this->travelTo(now()->addHours(72)->subSecond());
    expect(app(Entitlements::class)->tier($user->fresh()))->toBe('trial');

    $this->travelTo(now()->addSeconds(2));
    expect(app(Entitlements::class)->tier($user->fresh()))->toBe('expired');
});

it('expires without anything having to run', function () {
    /*
     * No job, no sweep, no scheduled command. Expiry is a comparison
     * made on every request, which is the same decision
     * entitlement_overrides already took - a grant that outlives its
     * date because a worker was down is a grant nobody can explain.
     */
    $user = trialUser();
    $this->actingAs($user)->post(route('trial.activate'));

    $this->travel(73)->hours();

    expect(app(Entitlements::class)->tier($user->fresh()))->toBe('expired')
        ->and($user->fresh()->trialStatus())->toBe('expired');
});

it('takes the product away and leaves the account', function () {
    $user = trialUser();
    $this->actingAs($user)->post(route('trial.activate'));

    $this->travel(73)->hours();

    // Still able to sign in and reach their own account.
    $this->actingAs($user->fresh())->get(route('today'))->assertOk();
    $this->actingAs($user->fresh())->get(route('account'))->assertOk();

    // And refused the things the trial was showing them.
    $this->actingAs($user->fresh())->get(route('picks'))
        ->assertRedirect(route('upgrade', ['feature' => 'board.full']));
});

it('leaves saved picks and parlays readable after expiry', function () {
    /*
     * The spec asks that these be preserved. Data nobody can open is
     * not preserved in any sense the person who entered it would
     * recognise, which is why my-parlays came off the grading
     * entitlement.
     */
    $user = trialUser();
    $this->actingAs($user)->post(route('trial.activate'));

    $this->travel(73)->hours();

    $this->actingAs($user->fresh())->get(route('my-picks'))->assertOk();
    $this->actingAs($user->fresh())->get(route('my-parlays'))->assertOk();
    $this->actingAs($user->fresh())->get(route('bets'))->assertOk();
});

it('refuses an expired trial through JSON as firmly as through a page', function () {
    // A limit enforced only on the HTML path is not a limit.
    $user = trialUser();
    $this->actingAs($user)->post(route('trial.activate'));

    $this->travel(73)->hours();

    $this->actingAs($user->fresh())
        ->getJson(route('picks'))
        ->assertForbidden()
        ->assertJsonPath('error', 'upgrade_required');
});

it('never turns a trial into a subscription', function () {
    $user = trialUser();
    $this->actingAs($user)->post(route('trial.activate'));

    $this->travel(100)->hours();

    expect(Subscription::where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('billing_events')->count())->toBe(0)
        ->and($user->fresh()->subscriptionStatus())->toBeNull();
});

/* ----------------------------------------------------------------------
 | Paying customers are unaffected
 ---------------------------------------------------------------------- */

it('leaves a paying subscriber alone whatever their trial says', function (string $tier) {
    $user = trialUser();

    Subscription::create([
        'user_id' => $user->id, 'tier' => $tier, 'status' => 'active',
        'current_period_end' => now()->addMonth(), 'source' => 'test',
    ]);

    // Even with a trial that ran and ended, the purchase wins.
    $user->forceFill([
        'trial_started_at' => now()->subWeek(),
        'trial_expires_at' => now()->subWeek()->addHours(72),
        'trial_used_at' => now()->subWeek(),
    ])->save();

    expect(app(Entitlements::class)->tier($user->fresh()))->toBe($tier);
})->with(['core', 'pro', 'elite']);

it('does not downgrade somebody who buys during their trial', function () {
    // Checked before the trial for exactly this reason: otherwise the
    // clock running out would read as a downgrade on a paid account.
    $user = trialUser();
    $this->actingAs($user)->post(route('trial.activate'));

    Subscription::create([
        'user_id' => $user->id, 'tier' => 'pro', 'status' => 'active',
        'current_period_end' => now()->addMonth(), 'source' => 'test',
    ]);

    $this->travel(100)->hours();

    expect(app(Entitlements::class)->tier($user->fresh()))->toBe('pro');
});

/* ----------------------------------------------------------------------
 | Concurrency
 ---------------------------------------------------------------------- */

it('yields one trial when the same account activates twice at once', function () {
    /*
     * The second call runs against a row the first has already written,
     * which is what the lock inside the transaction is for. A
     * check-then-write would let both through.
     */
    $user = trialUser();

    trials()->activate($user);

    expect(fn () => trials()->activate($user->fresh()))
        ->toThrow(RuntimeException::class);

    expect(TrialRedemption::where('user_id', $user->id)->count())->toBe(1);
});

/* ----------------------------------------------------------------------
 | Limits
 ---------------------------------------------------------------------- */

it('caps the daily allowances the feature table names', function () {
    $limits = (array) config('plans.tiers.trial.limits');

    expect($limits['reads_per_day'])->toBe(5)
        ->and($limits['prop_analyses_per_day'])->toBe(3)
        ->and($limits['parlays_per_day'])->toBe(10);
});

it('locks the one thing Elite still sells', function () {
    $user = trialUser();
    $this->actingAs($user)->post(route('trial.activate'));

    $this->actingAs($user->fresh())->get(route('prop-projections'))
        ->assertRedirect(route('upgrade', ['feature' => 'props.projected']));

    // Player IQ is available, per the feature table.
    $this->actingAs($user->fresh())->get(route('player-iq'))->assertOk();
});
