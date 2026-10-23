<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * The driver in use when there is no processor.
 *
 * Which is the current state of the world: nothing is on sale, no market
 * is proven, and there is no merchant account. This exists so that
 * `PAYMENT_DRIVER=none` is a real answer rather than a null reference.
 *
 * Every method refuses. Deliberately: the obvious alternative - a driver
 * that returns plausible ids and pretends the subscription worked - would
 * grant a paid tier to somebody who was never charged, and would do it
 * silently. A loud refusal is the only honest implementation.
 */
class UnconfiguredGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'none';
    }

    public function configured(): bool
    {
        return false;
    }

    public function createCustomer(User $user, string $paymentToken): string
    {
        throw $this->refuse();
    }

    public function createSubscription(string $customerId, string $tier, string $interval): GatewaySubscription
    {
        throw $this->refuse();
    }

    public function changePlan(string $subscriptionId, string $tier, string $interval): GatewaySubscription
    {
        throw $this->refuse();
    }

    public function cancelAtPeriodEnd(string $subscriptionId): void
    {
        throw $this->refuse();
    }

    public function resume(string $subscriptionId): void
    {
        throw $this->refuse();
    }

    public function refund(string $chargeId, int $amountCents): void
    {
        throw $this->refuse();
    }

    public function handleWebhook(Request $request): ?WebhookEvent
    {
        // Not "ignore it". A webhook arriving with no processor
        // configured means something is pointed at the wrong
        // environment, and that should be noticed.
        throw $this->refuse();
    }

    public function hostsBillingPortal(): bool
    {
        return false;
    }

    public function customerPortalUrl(string $customerId): ?string
    {
        return null;
    }

    private function refuse(): PaymentException
    {
        return new PaymentException(
            'No payment processor is configured (PAYMENT_DRIVER=none).',
            'Subscriptions are not on sale yet.',
        );
    }
}
