<?php

declare(strict_types=1);

use App\Models\BillingEvent;
use App\Notifications\PaymentFailedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

function postWebhook(string $driver, string $raw, string $signature): TestResponse
{
    return test()->call(
        'POST',
        route('webhooks.payments', ['driver' => $driver]),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            $driver === 'nmi' ? 'HTTP_WEBHOOK_SIGNATURE' : 'HTTP_STRIPE_SIGNATURE' => $signature,
        ],
        $raw,
    );
}

it('accepts a renewal that is properly signed and records it', function () {
    usingDriver('nmi');
    $user = payingUser(['current_period_end' => now()->addDays(2)]);

    [$raw, $signature] = nmiWebhook([
        'event_id' => 'evt_1',
        'event_type' => 'transaction.sale.success',
        'event_body' => [
            'subscription_id' => 'sub_1',
            'amount' => '59.00',
            'next_charge_date' => now()->addMonth()->toDateString(),
        ],
    ]);

    postWebhook('nmi', $raw, $signature)->assertOk()->assertJson(['ok' => true]);

    $event = BillingEvent::where('type', BillingEvent::RENEWED)->sole();

    expect($event->user_id)->toBe($user->id)
        ->and($event->amount_cents)->toBe(5900)
        // Stored as received, for the dispute nobody has had yet.
        ->and($event->payload['event_id'])->toBe('evt_1');

    expect($user->refresh()->subscription->current_period_end->isAfter(now()->addWeek()))->toBeTrue();
});

it('applies the same event only once however many times it is delivered', function () {
    usingDriver('nmi');
    payingUser(['current_period_end' => now()->addDay()]);

    [$raw, $signature] = nmiWebhook([
        'event_id' => 'evt_duplicate',
        'event_type' => 'transaction.sale.success',
        'event_body' => ['subscription_id' => 'sub_1', 'amount' => '59.00'],
    ]);

    postWebhook('nmi', $raw, $signature)->assertOk()->assertJson(['ok' => true]);

    // Every processor eventually sends the same event twice. The second
    // is acknowledged - it is not an error - and changes nothing.
    postWebhook('nmi', $raw, $signature)->assertOk()->assertJson(['duplicate' => true]);
    postWebhook('nmi', $raw, $signature)->assertOk()->assertJson(['duplicate' => true]);

    expect(BillingEvent::where('gateway_event_id', 'evt_duplicate')->count())->toBe(1);
});

it('rejects a body whose signature does not verify, and writes nothing', function () {
    usingDriver('nmi');
    payingUser();

    [$raw] = nmiWebhook(['event_id' => 'evt_2', 'event_type' => 'transaction.sale.success']);

    postWebhook('nmi', $raw, 't=nonce,s='.str_repeat('0', 64))
        ->assertStatus(400)
        ->assertJson(['error' => 'rejected']);

    expect(BillingEvent::count())->toBe(0);
});

it('rejects a body signed with somebody elses key', function () {
    usingDriver('nmi');
    payingUser();

    [$raw, $signature] = nmiWebhook(
        ['event_id' => 'evt_3', 'event_type' => 'transaction.sale.success'],
        key: 'a-different-key',
    );

    postWebhook('nmi', $raw, $signature)->assertStatus(400);

    expect(BillingEvent::count())->toBe(0);
});

it('rejects an unsigned body', function () {
    usingDriver('nmi');
    payingUser();

    [$raw] = nmiWebhook(['event_id' => 'evt_4', 'event_type' => 'transaction.sale.success']);

    postWebhook('nmi', $raw, '')->assertStatus(400);
    postWebhook('nmi', $raw, 'nonsense')->assertStatus(400);

    expect(BillingEvent::count())->toBe(0);
});

it('refuses every webhook when no signing key is configured', function () {
    usingDriver('nmi');
    config()->set('payments.drivers.nmi.webhook_key', null);
    payingUser();

    [$raw, $signature] = nmiWebhook(
        ['event_id' => 'evt_5', 'event_type' => 'transaction.sale.success'],
        key: 'anything',
    );

    // An unverified webhook is an anonymous stranger asking for a free
    // subscription. With no key there is no way to tell, so the only
    // safe answer is no.
    postWebhook('nmi', $raw, $signature)->assertStatus(400);

    expect(BillingEvent::count())->toBe(0);
});

it('verifies against the exact bytes, not a re-encoding of them', function () {
    usingDriver('nmi');
    payingUser(['tier' => 'pro']);

    // Signed as sent, with the whitespace it was sent with. A handler
    // that decoded and re-encoded before verifying would reject this,
    // and would then reject roughly every real webhook.
    $raw = "{\n  \"event_id\" : \"evt_spaced\",\n  \"event_type\":\"transaction.sale.success\",\n  \"event_body\": {\"subscription_id\":\"sub_1\",\"amount\":\"59.00\"}\n}";
    $nonce = 'n1';
    $signature = 't='.$nonce.',s='.hash_hmac('sha256', $nonce.'.'.$raw, 'test-signing-key');

    postWebhook('nmi', $raw, $signature)->assertOk()->assertJson(['ok' => true]);
});

it('acknowledges an event it has no interest in without storing it', function () {
    usingDriver('nmi');
    payingUser();

    [$raw, $signature] = nmiWebhook([
        'event_id' => 'evt_6',
        'event_type' => 'transaction.auth.success',
    ]);

    // Acknowledged so the processor stops retrying. Ignoring unknown
    // types is deliberate: the alternative is an endpoint that 500s
    // every time the processor ships a feature.
    postWebhook('nmi', $raw, $signature)->assertOk()->assertJson(['ignored' => true]);

    expect(BillingEvent::count())->toBe(0);
});

it('keeps an event about somebody we cannot identify, without a user', function () {
    usingDriver('nmi');
    payingUser();

    [$raw, $signature] = nmiWebhook([
        'event_id' => 'evt_orphan',
        'event_type' => 'transaction.sale.success',
        'event_body' => ['subscription_id' => 'sub_belonging_to_nobody', 'amount' => '19.00'],
    ]);

    postWebhook('nmi', $raw, $signature)->assertOk()->assertJson(['unmatched' => true]);

    // Kept, because it is the only evidence that the processor thinks it
    // is billing somebody on our behalf.
    $event = BillingEvent::sole();

    expect($event->user_id)->toBeNull()
        ->and($event->reason)->toContain('No subscription matched');
});

it('does not hand a renewal to the first account in the table when the event names nobody', function () {
    usingDriver('nmi');
    $user = payingUser(['current_period_end' => now()->addDay()]);
    $end = $user->subscription->current_period_end;

    [$raw, $signature] = nmiWebhook([
        'event_id' => 'evt_nameless',
        'event_type' => 'transaction.sale.success',
        'event_body' => ['amount' => '59.00', 'next_charge_date' => now()->addYear()->toDateString()],
    ]);

    postWebhook('nmi', $raw, $signature)->assertOk()->assertJson(['unmatched' => true]);

    expect($user->refresh()->subscription->current_period_end->toDateString())
        ->toBe($end->toDateString());
});

it('will not let a webhook decide which tier somebody is on', function () {
    usingDriver('nmi');
    $user = payingUser(['tier' => 'daily']);

    [$raw, $signature] = nmiWebhook([
        'event_id' => 'evt_7',
        'event_type' => 'transaction.sale.success',
        'event_body' => [
            'subscription_id' => 'sub_1',
            'amount' => '59.00',
            // Ignored. A webhook that could name the tier would be a
            // webhook that could grant Pro, and this endpoint cannot be
            // behind a login.
            'tier' => 'pro',
            'plan' => 'pro',
        ],
    ]);

    postWebhook('nmi', $raw, $signature)->assertOk();

    expect($user->refresh()->subscription->effectiveTier())->toBe('daily');
});

it('emails the customer when a payment fails, and does not cut them off', function () {
    Notification::fake();

    usingDriver('nmi');
    $user = payingUser(['current_period_end' => now()->addDays(5)]);

    [$raw, $signature] = nmiWebhook([
        'event_id' => 'evt_fail',
        'event_type' => 'transaction.sale.failure',
        'event_body' => ['subscription_id' => 'sub_1', 'amount' => '59.00'],
    ]);

    postWebhook('nmi', $raw, $signature)->assertOk();

    Notification::assertSentTo($user, PaymentFailedNotification::class);

    // Past due, not cancelled: dunning gets three attempts over seven
    // days before anything is taken away.
    expect($user->refresh()->subscription->status)->toBe('past_due')
        ->and($user->subscription->effectiveTier())->toBe('pro');
});

it('verifies a stripe webhook with stripes own scheme', function () {
    usingDriver('stripe');
    $user = payingUser(['gateway' => 'stripe', 'gateway_customer_id' => 'cus_1']);

    [$raw, $signature] = stripeWebhook([
        'id' => 'evt_stripe_1',
        'type' => 'invoice.payment_succeeded',
        'created' => now()->timestamp,
        'data' => ['object' => [
            'customer' => 'cus_1',
            'subscription' => 'sub_1',
            'amount_paid' => 5900,
            'currency' => 'usd',
            'lines' => ['data' => [['period' => ['end' => now()->addMonth()->timestamp]]]],
        ]],
    ]);

    postWebhook('stripe', $raw, $signature)->assertOk()->assertJson(['ok' => true]);

    expect(BillingEvent::sole()->amount_cents)->toBe(5900)
        ->and($user->refresh()->subscription->status)->toBe('active');
});

it('rejects a stripe webhook from outside the replay window', function () {
    usingDriver('stripe');
    payingUser(['gateway' => 'stripe']);

    [$raw, $signature] = stripeWebhook(
        ['id' => 'evt_old', 'type' => 'invoice.payment_succeeded', 'data' => ['object' => []]],
        timestamp: now()->subHour()->timestamp,
    );

    // Stripe's `t` really is a timestamp, so the window is enforceable
    // and is enforced. NMI's is a nonce, which is why dedupe by event id
    // is the whole defence there.
    postWebhook('stripe', $raw, $signature)->assertStatus(400);

    expect(BillingEvent::count())->toBe(0);
});

it('reads a stripe cancellation as a cancellation and not as a plan change', function () {
    usingDriver('stripe');
    $user = payingUser(['gateway' => 'stripe', 'gateway_customer_id' => 'cus_1']);

    [$raw, $signature] = stripeWebhook([
        'id' => 'evt_cancel',
        'type' => 'customer.subscription.updated',
        'created' => now()->timestamp,
        'data' => ['object' => [
            'id' => 'sub_1',
            'customer' => 'cus_1',
            'cancel_at_period_end' => true,
            'current_period_end' => now()->addWeek()->timestamp,
        ]],
    ]);

    postWebhook('stripe', $raw, $signature)->assertOk();

    $subscription = $user->refresh()->subscription;

    // Otherwise the account looks fine right up to the day it stops.
    expect($subscription->cancel_at_period_end)->toBeTrue()
        ->and($subscription->effectiveTier())->toBe('pro');
});

it('refuses a webhook aimed at a processor that is not configured', function () {
    config()->set('payments.driver', 'none');
    config()->set('payments.drivers.nmi.webhook_key', null);
    payingUser();

    [$raw, $signature] = nmiWebhook(
        ['event_id' => 'evt_8', 'event_type' => 'transaction.sale.success'],
        key: 'whatever',
    );

    postWebhook('nmi', $raw, $signature)->assertStatus(400);
});

it('has no webhook endpoint for a processor it does not support', function () {
    $this->post('/webhooks/payments/paypal')->assertNotFound();
});
