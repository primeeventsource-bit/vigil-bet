<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\BillingEvent;
use App\Models\User;
use App\Notifications\DowngradedNotification;
use App\Services\Payments\SubscriptionProjector;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| §5: three retries over seven days, an email at each, then a downgrade to
| Free with the data kept.
|--------------------------------------------------------------------------
|
| The retries belong to the processor - it holds the card. What is tested
| here is the deadline, which is ours, and the promise that nothing is
| deleted when it passes.
|
*/

beforeEach(function () {
    usingDriver('nmi');
    Notification::fake();
});

function failPayments(User $user, int $times, int $daysApart = 2): void
{
    app(SubscriptionProjector::class)->record($user, BillingEvent::SUBSCRIBED, [
        'tier' => 'pro', 'interval' => 'monthly',
        'period_end' => now()->addMonth(),
        'occurred_at' => now()->subDays($times * $daysApart + 1),
    ]);

    foreach (range(1, $times) as $i) {
        app(SubscriptionProjector::class)->record($user, BillingEvent::PAYMENT_FAILED, [
            'occurred_at' => now()->subDays(($times - $i) * $daysApart),
        ]);
    }
}

it('waits while the processor still has attempts left', function () {
    $user = payingUser();
    failPayments($user, 1);

    $this->artisan('vigil:dunning')->assertSuccessful();

    // Still Pro. One failed payment is not a reason to take anything
    // away from somebody whose card may simply have expired.
    expect($user->refresh()->subscription->effectiveTier())->toBe('pro');
    Notification::assertNothingSent();
});

it('waits on the second attempt too', function () {
    $user = payingUser();
    failPayments($user, 2, daysApart: 1);

    $this->artisan('vigil:dunning')->assertSuccessful();

    expect($user->refresh()->subscription->effectiveTier())->toBe('pro');
});

it('moves the account to free after the third failure', function () {
    $user = payingUser();
    failPayments($user, 3, daysApart: 1);

    $this->artisan('vigil:dunning')->assertSuccessful();

    $subscription = $user->refresh()->subscription;

    expect($subscription->tier)->toBe('free')
        ->and($subscription->effectiveTier())->toBe('free')
        ->and($subscription->current_period_end)->toBeNull();

    Notification::assertSentTo($user, DowngradedNotification::class);

    // And the gate follows, which is the half that decides what the
    // customer can actually open.
    $this->actingAs($user)->get(route('picks'))
        ->assertRedirect(route('upgrade', ['feature' => 'board.full']));
});

it('moves the account to free when the window runs out even if the processor gave up early', function () {
    $user = payingUser();

    // One failure, eight days ago, and nothing since. A processor that
    // stops trying and stops sending would otherwise leave this customer
    // past due - and on Pro - indefinitely.
    app(SubscriptionProjector::class)->record($user, BillingEvent::SUBSCRIBED, [
        'tier' => 'pro', 'period_end' => now()->addMonth(), 'occurred_at' => now()->subMonth(),
    ]);
    app(SubscriptionProjector::class)->record($user, BillingEvent::PAYMENT_FAILED, [
        'occurred_at' => now()->subDays(8),
    ]);

    $this->artisan('vigil:dunning')->assertSuccessful();

    expect($user->refresh()->subscription->tier)->toBe('free');
});

it('keeps everything the customer had when it downgrades them', function () {
    $user = payingUser();
    failPayments($user, 3, daysApart: 1);

    $before = BillingEvent::count();

    $this->artisan('vigil:dunning')->assertSuccessful();

    // §5 keeps the data, and so does this: the account still exists, the
    // history is intact, and one more event has been added to it.
    expect($user->exists)->toBeTrue()
        ->and(BillingEvent::count())->toBe($before + 1)
        ->and(BillingEvent::where('type', BillingEvent::DOWNGRADED)->exists())->toBeTrue();
});

it('records the downgrade in the audit log with a reason', function () {
    $user = payingUser();
    failPayments($user, 3, daysApart: 1);

    $this->artisan('vigil:dunning')->assertSuccessful();

    $audit = AuditEvent::where('action', 'billing.downgraded')->sole();

    expect($audit->before['tier'])->toBe('pro')
        ->and($audit->after['tier'])->toBe('free')
        ->and($audit->reason)->toContain('Dunning exhausted');
});

it('stops dunning a customer whose payment went through in the end', function () {
    $user = payingUser();
    failPayments($user, 3, daysApart: 1);

    app(SubscriptionProjector::class)->record($user, BillingEvent::PAYMENT_RECOVERED, [
        'period_end' => now()->addMonth(),
    ]);

    $this->artisan('vigil:dunning')->assertSuccessful();

    expect($user->refresh()->subscription->tier)->toBe('pro')
        ->and($user->subscription->status)->toBe('active');

    Notification::assertNothingSent();
});

it('changes nothing on a dry run', function () {
    $user = payingUser();
    failPayments($user, 3, daysApart: 1);

    $this->artisan('vigil:dunning', ['--dry-run' => true])->assertSuccessful();

    expect($user->refresh()->subscription->tier)->toBe('pro');
    Notification::assertNothingSent();
});

it('leaves alone an account that is already on free', function () {
    $user = payingUser(['tier' => 'free', 'status' => 'past_due']);

    $this->artisan('vigil:dunning')->assertSuccessful();

    expect(BillingEvent::count())->toBe(0);
    Notification::assertNothingSent();
});
