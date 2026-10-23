<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Everything the product is allowed to ask a payment processor to do.
 *
 * The interface §5 asks for, and the reason it exists: sports-information
 * subscriptions are routinely classed as high risk, and Stripe's
 * prohibited-business list names sports forecasting with a monetary or
 * material prize. Vigil may have to change processor at short notice,
 * possibly while the current one is closing the account. That is not the
 * week to be rewriting controllers.
 *
 * So nothing above this interface knows which processor is in use.
 * `PaymentDrivers` resolves one from `PAYMENT_DRIVER`, and §13 is tested
 * against the result: switching between `nmi` and `stripe` must require
 * no code changes.
 *
 * Card data never passes through here. Every driver takes a token minted
 * in the customer's browser by the processor's own hosted field, which is
 * the whole of Vigil's PCI position.
 */
interface PaymentGateway
{
    /** Which driver this is, as it appears in billing_events. */
    public function name(): string;

    /**
     * Whether this driver has what it needs to talk to anybody.
     *
     * Asked before a checkout is offered, so a missing key shows up as
     * "not available" rather than as a failed card.
     */
    public function configured(): bool;

    /**
     * Create the processor's record of this customer and store a card
     * against it, from a token minted in the browser.
     *
     * @throws PaymentException
     */
    public function createCustomer(User $user, string $paymentToken): string;

    /**
     * Start a subscription. Returns the processor's handle and the period
     * it has been paid up to.
     *
     * @throws PaymentException
     */
    public function createSubscription(string $customerId, string $tier, string $interval): GatewaySubscription;

    /**
     * Move an existing subscription to a different tier or interval.
     *
     * @throws PaymentException
     */
    public function changePlan(string $subscriptionId, string $tier, string $interval): GatewaySubscription;

    /**
     * Stop it renewing, without taking anything away before the period
     * the customer has already paid for runs out.
     *
     * @throws PaymentException
     */
    public function cancelAtPeriodEnd(string $subscriptionId): void;

    /**
     * Undo that, while the period is still running.
     *
     * @throws PaymentException
     */
    public function resume(string $subscriptionId): void;

    /**
     * Give money back. Cents, because every amount in this product is
     * cents.
     *
     * @throws PaymentException
     */
    public function refund(string $chargeId, int $amountCents): void;

    /**
     * Verify an inbound webhook and translate it into our vocabulary.
     *
     * Returns null for an event this product does not care about, which
     * is most of them. Throws if the signature does not verify - an
     * unverified webhook is an anonymous stranger asking for a free
     * subscription.
     *
     * @throws PaymentException
     */
    public function handleWebhook(Request $request): ?WebhookEvent;

    /**
     * Whether this processor hosts a card-management page at all.
     *
     * Asked instead of calling customerPortalUrl() to find out, because
     * on Stripe that call creates a billing-portal session - a page can
     * only be offered if we know it exists before offering it.
     */
    public function hostsBillingPortal(): bool;

    /**
     * Where to send a customer to manage their card, if the processor
     * hosts such a page. Null where it does not, which is why §5 marks
     * this one nullable - NMI has no equivalent of Stripe's portal, and
     * Account has to be able to cope with that rather than linking
     * somewhere that does not exist.
     */
    public function customerPortalUrl(string $customerId): ?string;
}
