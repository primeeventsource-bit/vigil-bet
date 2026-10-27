<?php

declare(strict_types=1);

use App\Models\BillingEvent;
use App\Services\Payments\Nmi\NmiGateway;
use App\Services\Payments\PaymentDrivers;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\Stripe\StripeGateway;
use App\Services\Payments\UnconfiguredGateway;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| §13: "Switching PAYMENT_DRIVER between nmi and stripe requires no code
| changes."
|--------------------------------------------------------------------------
|
| Tested by doing it. The same subscribe request is sent twice, with
| nothing changed between the two runs but configuration, and both are
| expected to produce the same billing event.
|
| This matters more than it looks. Sports-information subscriptions are
| routinely classed as high risk and Stripe's prohibited list names sports
| forecasting for prizes, so the switch may have to happen under pressure,
| at short notice, while the current processor is closing the account.
|
*/

it('resolves whichever driver the configuration names', function (string $driver, string $class) {
    config()->set('payments.driver', $driver);

    expect(app(PaymentGateway::class))->toBeInstanceOf($class);
})->with([
    ['nmi', NmiGateway::class],
    ['stripe', StripeGateway::class],
    ['none', UnconfiguredGateway::class],
]);

it('refuses everything rather than guessing when the driver name is nonsense', function () {
    config()->set('payments.driver', 'definitely-not-a-processor');

    $gateway = app(PaymentGateway::class);

    // Offline, not broken. A typo in an environment variable should take
    // the checkout down and say so, not the whole site.
    expect($gateway)->toBeInstanceOf(UnconfiguredGateway::class)
        ->and($gateway->configured())->toBeFalse();
});

it('names every driver it can resolve', function () {
    expect(PaymentDrivers::names())->toEqualCanonicalizing(['nmi', 'stripe', 'none']);
});

it('subscribes a customer identically through either processor', function (string $driver) {
    usingDriver($driver);

    Http::fake([
        // NMI: a vault record, then a subscription against it.
        'nmi.test/api/v5/customers' => Http::response(['id' => 'vault_77']),
        'nmi.test/api/v5/subscriptions' => Http::response([
            'id' => 'nmi_sub_9',
            'next_charge_date' => now()->addMonth()->toDateString(),
        ]),
        // Stripe: a customer, then a subscription.
        'stripe.test/api/v1/customers' => Http::response(['id' => 'cus_77']),
        'stripe.test/api/v1/subscriptions' => Http::response([
            'id' => 'sub_9',
            'current_period_end' => now()->addMonth()->timestamp,
        ]),
    ]);

    $user = payingUser();

    $this->actingAs($user)->post(route('billing.subscribe'), [
        'tier' => 'pro',
        'interval' => 'monthly',
        // Minted in the browser by the processor's own hosted field.
        // Never a card number: that is the whole of this product's PCI
        // position.
        'payment_token' => 'tok_from_the_browser',
    ])->assertRedirect(route('account'));

    $event = BillingEvent::sole();

    expect($event->type)->toBe(BillingEvent::SUBSCRIBED)
        ->and($event->driver)->toBe($driver)
        ->and($event->tier)->toBe('pro')
        ->and($event->interval)->toBe('monthly')
        /*
         * Read from config/plans.php rather than written out again.
         * The comment here has always said the pricing page and the
         * charge cannot drift apart - and then a second copy of the
         * price sat underneath it, which is exactly how they drift.
         * This caught it at the rename: 5900 became 5999 in one place.
         */
        ->and($event->amount_cents)->toBe((int) config('plans.tiers.pro.monthly_cents'))
        ->and($event->period_end)->not->toBeNull();

    // And the projection agrees.
    expect($user->refresh()->subscription->effectiveTier())->toBe('pro');
})->with(['nmi', 'stripe']);

it('never sends a card number anywhere, only a token', function () {
    usingDriver('nmi');

    Http::fake([
        'nmi.test/api/v5/customers' => Http::response(['id' => 'vault_77']),
        'nmi.test/api/v5/subscriptions' => Http::response(['id' => 'nmi_sub_9']),
    ]);

    $this->actingAs(payingUser())->post(route('billing.subscribe'), [
        'tier' => 'core',
        'interval' => 'yearly',
        'payment_token' => 'tok_abc',
    ]);

    Http::assertSent(function ($request) {
        $body = json_encode($request->data());

        // The token goes out; nothing that looks like a PAN does. The
        // form field is a hidden input for exactly this reason - there is
        // nowhere for a customer to type sixteen digits.
        return str_contains($body, 'tok_abc')
            && preg_match('/\b\d{13,19}\b/', $body) !== 1;
    });
});

it('will not take money when no processor is configured', function () {
    config()->set('payments.driver', 'none');

    $this->actingAs(payingUser())->post(route('billing.subscribe'), [
        'tier' => 'pro',
        'interval' => 'monthly',
        'payment_token' => 'tok_abc',
    ])->assertSessionHasErrors('tier');

    // The important half: no event, so no tier. A driver that returned a
    // plausible id and shrugged would have granted Pro to somebody who
    // was never charged.
    expect(BillingEvent::count())->toBe(0);
});

it('refuses a tier nobody can buy and an interval that does not exist', function (array $payload) {
    usingDriver('nmi');
    Http::fake();

    $this->actingAs(payingUser())
        ->post(route('billing.subscribe'), $payload + ['payment_token' => 'tok_abc'])
        ->assertSessionHasErrors();

    expect(BillingEvent::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    [['tier' => 'free', 'interval' => 'monthly']],
    [['tier' => 'pro', 'interval' => 'fortnightly']],
    [['tier' => 'platinum', 'interval' => 'monthly']],
]);

it('declines rather than subscribing when the processor says no', function () {
    usingDriver('nmi');

    Http::fake([
        'nmi.test/api/v5/customers' => Http::response(['message' => 'Card declined'], 402),
    ]);

    $this->actingAs(payingUser())->post(route('billing.subscribe'), [
        'tier' => 'pro',
        'interval' => 'monthly',
        'payment_token' => 'tok_abc',
    ])->assertSessionHasErrors('payment_token');

    expect(BillingEvent::count())->toBe(0);
});
