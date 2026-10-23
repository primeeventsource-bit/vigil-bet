<?php

declare(strict_types=1);

use App\Models\BillingEvent;
use App\Services\Payments\SubscriptionProjector;
use Illuminate\Support\Facades\Http;

function projector(): SubscriptionProjector
{
    return app(SubscriptionProjector::class);
}

beforeEach(function () {
    usingDriver('nmi');
});

/*
|--------------------------------------------------------------------------
| §13: "Cancelling a subscription takes one click and keeps access until
| period end."
|--------------------------------------------------------------------------
*/

it('cancels in a single post and takes nothing away today', function () {
    Http::fake(['nmi.test/*' => Http::response([])]);

    $user = payingUser(['current_period_end' => now()->addDays(20)]);

    // One request. No confirmation page, no retention offer in the way,
    // no second step - §4 asks for one click and no dark patterns.
    $this->actingAs($user)->post(route('billing.cancel'))->assertRedirect(route('account'));

    $subscription = $user->refresh()->subscription;

    expect($subscription->cancel_at_period_end)->toBeTrue()
        ->and($subscription->cancelled_at)->not->toBeNull()
        // Still Pro. They paid for the month.
        ->and($subscription->effectiveTier())->toBe('pro');

    expect(BillingEvent::sole()->type)->toBe(BillingEvent::CANCEL_SCHEDULED);
});

it('offers cancellation as one button and asks nothing before it', function () {
    Http::fake();

    $response = $this->actingAs(payingUser(['current_period_end' => now()->addMonth()]))
        ->get(route('account'))
        ->assertOk();

    $response->assertSee(route('billing.cancel'), escape: false)
        ->assertSee('Cancel subscription')
        ->assertSee('You keep everything until');

    // No funnel: the button posts straight to the cancel route, so there
    // is no interstitial page for a retention offer to live on.
    expect(substr_count($response->getContent(), route('billing.cancel')))->toBe(1);
});

it('stops granting the tier once the period actually ends', function () {
    Http::fake(['nmi.test/*' => Http::response([])]);

    $user = payingUser(['current_period_end' => now()->addDays(3)]);

    $this->actingAs($user)->post(route('billing.cancel'));

    expect($user->refresh()->subscription->effectiveTier())->toBe('pro');

    /*
     * Four days on, and the processor has said nothing further. Access
     * still has to stop: the date is what decides, not a webhook that
     * may never arrive.
     */
    $this->travel(4)->days();

    expect($user->refresh()->subscription->effectiveTier())->toBe('free');

    // And the gate agrees, which is the half that matters.
    $this->actingAs($user)->get(route('picks'))
        ->assertRedirect(route('upgrade', ['feature' => 'board.full']));
});

it('keeps a paying customer in when a renewal webhook never turns up', function () {
    $user = payingUser(['current_period_end' => now()->subDays(2)]);

    // Active, past the period end, no cancellation. That is a missed
    // message on our side, and locking out a paying customer over our
    // own missed message would be the wrong way to be wrong.
    expect($user->subscription->effectiveTier())->toBe('pro');
});

it('records a cancellation even when the processor cannot be reached', function () {
    Http::fake(['nmi.test/*' => Http::response(['message' => 'gateway down'], 503)]);

    $user = payingUser(['current_period_end' => now()->addWeek()]);

    $this->actingAs($user)->post(route('billing.cancel'))->assertRedirect(route('account'));

    // The customer asked to leave. That is a fact about them, not about
    // the processor, and a cancellation lost to an HTTP error is exactly
    // the thing that ends up in a complaint - correctly.
    expect($user->refresh()->subscription->cancel_at_period_end)->toBeTrue();
});

it('resumes a cancelled subscription and renews as normal', function () {
    usingDriver('stripe');
    Http::fake(['stripe.test/*' => Http::response(['id' => 'sub_1'])]);

    $user = payingUser([
        'gateway' => 'stripe',
        'cancel_at_period_end' => true,
        'cancelled_at' => now()->subDay(),
        'current_period_end' => now()->addWeek(),
    ]);

    $this->actingAs($user)->post(route('billing.resume'))->assertRedirect(route('account'));

    $subscription = $user->refresh()->subscription;

    expect($subscription->cancel_at_period_end)->toBeFalse()
        ->and($subscription->cancelled_at)->toBeNull()
        ->and($subscription->status)->toBe('active');
});

it('says so plainly when the processor cannot resume at all', function () {
    Http::fake();

    $user = payingUser([
        'cancel_at_period_end' => true,
        'current_period_end' => now()->addWeek(),
    ]);

    // NMI deletes rather than schedules, so there is nothing to
    // un-delete. Saying that beats a button that silently does nothing.
    $this->actingAs($user)->post(route('billing.resume'))->assertSessionHasErrors('resume');
});

/*
|--------------------------------------------------------------------------
| The projection
|--------------------------------------------------------------------------
*/

it('derives the whole subscription from the log and nothing else', function () {
    $user = payingUser();

    projector()->record($user, BillingEvent::SUBSCRIBED, [
        'tier' => 'daily', 'interval' => 'monthly',
        'gateway_customer_id' => 'cust_9', 'gateway_subscription_id' => 'sub_9',
        'period_end' => now()->addMonth(),
    ]);

    $subscription = $user->refresh()->subscription;

    expect($subscription->tier)->toBe('daily')
        ->and($subscription->interval)->toBe('monthly')
        ->and($subscription->status)->toBe('active')
        ->and($subscription->gateway_subscription_id)->toBe('sub_9')
        // Says where it came from, so a projection built by hand is
        // distinguishable from one replayed off the log.
        ->and($subscription->source)->toBe('billing_events')
        ->and($subscription->last_billing_event_id)->toBe(BillingEvent::sole()->id);
});

it('rebuilds the same answer from the same log, however many times', function () {
    $user = payingUser();

    projector()->record($user, BillingEvent::SUBSCRIBED, [
        'tier' => 'pro', 'interval' => 'yearly', 'period_end' => now()->addYear(),
    ]);
    projector()->record($user, BillingEvent::PAYMENT_FAILED, []);
    projector()->record($user, BillingEvent::PAYMENT_RECOVERED, ['period_end' => now()->addYear()]);

    $first = $user->refresh()->subscription->only(['tier', 'status', 'interval']);

    // A full replay rather than an incremental update, so the recovery
    // path is the path that runs every day and cannot rot unnoticed.
    projector()->rebuild($user);
    projector()->rebuild($user);

    expect($user->refresh()->subscription->only(['tier', 'status', 'interval']))->toBe($first)
        ->and($first['status'])->toBe('active');
});

it('does not let an event with no tier blank out the tier', function () {
    $user = payingUser();

    projector()->record($user, BillingEvent::SUBSCRIBED, [
        'tier' => 'pro', 'interval' => 'monthly', 'period_end' => now()->addMonth(),
    ]);

    // A renewal that names no plan. Every arm of the fold leaves a
    // missing field alone for exactly this reason.
    projector()->record($user, BillingEvent::RENEWED, ['period_end' => now()->addMonths(2)]);

    expect($user->refresh()->subscription->tier)->toBe('pro');
});

it('clears an old cancellation when somebody subscribes again', function () {
    $user = payingUser();

    projector()->record($user, BillingEvent::SUBSCRIBED, ['tier' => 'daily', 'period_end' => now()->addMonth()]);
    projector()->record($user, BillingEvent::CANCEL_SCHEDULED, ['period_end' => now()->addMonth()]);
    projector()->record($user, BillingEvent::SUBSCRIBED, ['tier' => 'pro', 'period_end' => now()->addMonth()]);

    $subscription = $user->refresh()->subscription;

    // Otherwise a customer who left and came back would be sold a plan
    // that was already scheduled to stop.
    expect($subscription->cancel_at_period_end)->toBeFalse()
        ->and($subscription->cancelled_at)->toBeNull()
        ->and($subscription->effectiveTier())->toBe('pro');
});

it('does not treat a refund as a change of access', function () {
    $user = payingUser();

    projector()->record($user, BillingEvent::SUBSCRIBED, ['tier' => 'pro', 'period_end' => now()->addMonth()]);
    projector()->record($user, BillingEvent::REFUNDED, ['amount_cents' => 5900]);

    // Money going back is not a reason to cut off the customer we just
    // apologised to.
    expect($user->refresh()->subscription->effectiveTier())->toBe('pro');
});

it('counts the failures since the last thing that went right', function () {
    $user = payingUser();

    projector()->record($user, BillingEvent::SUBSCRIBED, ['tier' => 'pro', 'period_end' => now()->addMonth()]);
    projector()->record($user, BillingEvent::PAYMENT_FAILED, []);
    projector()->record($user, BillingEvent::PAYMENT_FAILED, []);

    expect(projector()->failuresSinceLastSuccess($user))->toBe(2);

    projector()->record($user, BillingEvent::RENEWED, ['period_end' => now()->addMonths(2)]);

    // Read from the log rather than kept as a counter, because a counter
    // is a thing that can be wrong.
    expect(projector()->failuresSinceLastSuccess($user))->toBe(0);
});

it('never rewrites or deletes a billing event', function () {
    $user = payingUser();

    $event = projector()->record($user, BillingEvent::SUBSCRIBED, ['tier' => 'pro']);

    // This is the table that answers chargebacks.
    expect(fn () => $event->update(['amount_cents' => 1]))
        ->toThrow(RuntimeException::class, 'append-only');

    expect(fn () => $event->delete())
        ->toThrow(RuntimeException::class, 'append-only');
});

it('keeps a hand-granted plan when its first webhook arrives', function () {
    // The owner command and, later, an admin grant write a subscription
    // row with no events behind it. Replaying from empty would read the
    // first failed payment as proof they were never subscribed.
    $user = payingUser(['tier' => 'pro', 'source' => 'owner_command']);

    projector()->record($user, BillingEvent::PAYMENT_FAILED, []);

    expect($user->refresh()->subscription->tier)->toBe('pro');
});
