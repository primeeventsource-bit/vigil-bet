<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\User;

/**
 * Credentials and plan ids for a driver, so a test can switch processor
 * the way an operator would - by setting configuration and nothing else.
 */
function usingDriver(string $driver): void
{
    config()->set('payments.driver', $driver);
    config()->set('payments.stripe_enabled', true);

    config()->set("payments.drivers.{$driver}.api_key", 'test-key');
    config()->set("payments.drivers.{$driver}.webhook_key", 'test-signing-key');
    config()->set("payments.drivers.{$driver}.base_url", 'https://'.$driver.'.test/api');

    foreach (['daily', 'pro'] as $tier) {
        foreach (['monthly', 'yearly'] as $interval) {
            config()->set("payments.drivers.{$driver}.plans.{$tier}.{$interval}", "{$driver}-{$tier}-{$interval}");
        }
    }
}

function payingUser(array $subscription = []): User
{
    $user = User::forceCreate([
        'name' => 'Sam Rivera',
        'email' => 'sam'.fake()->unique()->randomNumber(6).'@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
    ]);

    if ($subscription !== []) {
        Subscription::create($subscription + [
            'user_id' => $user->id,
            'tier' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
            'gateway' => config('payments.driver'),
            'gateway_customer_id' => 'cust_1',
            'gateway_subscription_id' => 'sub_1',
            'source' => 'test',
        ]);
    }

    return $user->refresh();
}

/** An NMI webhook, signed the way NMI signs one. */
function nmiWebhook(array $body, ?string $key = null): array
{
    $raw = json_encode($body, JSON_THROW_ON_ERROR);
    $nonce = 'nonce-'.bin2hex(random_bytes(4));
    $key ??= (string) config('payments.drivers.nmi.webhook_key');

    return [$raw, 't='.$nonce.',s='.hash_hmac('sha256', $nonce.'.'.$raw, $key)];
}

/** A Stripe webhook, signed the way Stripe signs one. */
function stripeWebhook(array $body, ?int $timestamp = null, ?string $key = null): array
{
    $raw = json_encode($body, JSON_THROW_ON_ERROR);
    $timestamp ??= now()->timestamp;
    $key ??= (string) config('payments.drivers.stripe.webhook_key');

    return [$raw, 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$raw, $key)];
}
