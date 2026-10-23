<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BillingEvent;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\PaymentFailedNotification;
use App\Services\Payments\PaymentDrivers;
use App\Services\Payments\PaymentException;
use App\Services\Payments\SubscriptionProjector;
use App\Services\Payments\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where the processor talks to us.
 *
 * Three properties, all of which §5 asks for by name and all of which
 * are the kind of thing that is fine until the day it is not:
 *
 *  - **Signature-verified.** Done in the driver, before the body is
 *    parsed. An unverified webhook is an anonymous stranger asking for a
 *    free Pro subscription, and this endpoint cannot be behind a login.
 *  - **Idempotent.** The processor's event id goes into a unique column.
 *    Every processor eventually delivers the same event twice; here the
 *    duplicate fails at the database rather than in a handler somebody
 *    might later refactor away.
 *  - **Append-only.** The event is stored as received and the
 *    subscription is derived from the log, so a chargeback argument has
 *    something to be settled against.
 */
class WebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $driver,
        SubscriptionProjector $projector,
    ): JsonResponse {
        /*
         * Resolved from the path, not from PAYMENT_DRIVER. During a
         * switchover both processors are live and both are still
         * sending; handing a Stripe payload to the NMI verifier would
         * reject a real event as a mystery.
         */
        $gateway = PaymentDrivers::make($driver);

        try {
            $event = $gateway->handleWebhook($request);
        } catch (PaymentException $e) {
            /*
             * 400, not 500. A processor retries a 500 and gives up on a
             * 400, and an unverifiable body will never become verifiable
             * however many times it is sent.
             */
            Log::warning('Rejected a billing webhook', [
                'driver' => $gateway->name(),
                'reason' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'rejected'], 400);
        }

        // Verified, but of no interest. Acknowledged so the processor
        // stops retrying something we will never act on.
        if ($event === null) {
            return response()->json(['ignored' => true]);
        }

        $user = $this->userFor($event->customerId, $event->subscriptionId);

        if ($user === null) {
            /*
             * Verified, well-formed, and about nobody we know. Recorded
             * anyway, without a user, because the alternative is losing
             * the only evidence that the processor thinks it is billing
             * somebody on our behalf.
             */
            $this->orphan($gateway->name(), $event);

            return response()->json(['unmatched' => true]);
        }

        try {
            $projector->record($user, $event->type, [
                'driver' => $gateway->name(),
                'gateway_event_id' => $event->id,
                'gateway_customer_id' => $event->customerId,
                'gateway_subscription_id' => $event->subscriptionId,
                // The processor does not get to name the tier: it is
                // whatever we recorded when the plan was bought. A
                // webhook that could set the tier would be a webhook
                // that could grant Pro.
                'tier' => $event->tier ?? $user->subscription?->tier,
                'interval' => $event->interval ?? $user->subscription?->interval,
                'amount_cents' => $event->amountCents,
                'currency' => $event->currency,
                'period_end' => $event->periodEnd,
                'payload' => $event->payload,
                'occurred_at' => $event->occurredAt ?? now(),
            ]);
        } catch (QueryException $e) {
            // The unique column doing its job: this event has already
            // been applied. Acknowledged, because it is not an error.
            if ($this->isDuplicate($e)) {
                return response()->json(['duplicate' => true]);
            }

            throw $e;
        }

        /*
         * §5 asks for an email at each retry. Sent here rather than in
         * the projector, because the projector is a pure fold over the
         * log and must stay replayable - rebuilding the projection
         * should never post a customer three old emails.
         */
        if ($event->type === BillingEvent::PAYMENT_FAILED) {
            $this->warnAboutFailure($user, $projector);
        }

        return response()->json(['ok' => true]);
    }

    private function warnAboutFailure(User $user, SubscriptionProjector $projector): void
    {
        $schedule = (array) config('payments.dunning.retry_after_days', [1, 3, 7]);

        $user->notify(new PaymentFailedNotification(
            attempt: $projector->failuresSinceLastSuccess($user),
            ofAttempts: count($schedule),
            // The date access actually stops, which is the only part of
            // such an email a customer needs.
            accessUntil: $user->refresh()->subscription?->current_period_end?->toFormattedDateString(),
        ));
    }

    /**
     * Who this event is about.
     *
     * Matched on the processor's own handles, never on an email in the
     * payload - an email in a webhook body is an email somebody can put
     * in a webhook body.
     */
    private function userFor(?string $customerId, ?string $subscriptionId): ?User
    {
        if ($customerId === null && $subscriptionId === null) {
            // Without a handle there is nothing to match on. A query
            // with no conditions would return the first subscription in
            // the table and hand somebody else's account a renewal.
            return null;
        }

        $subscription = Subscription::query()
            ->where(function ($q) use ($customerId, $subscriptionId): void {
                if ($customerId !== null) {
                    $q->orWhere('gateway_customer_id', $customerId);
                }

                if ($subscriptionId !== null) {
                    $q->orWhere('gateway_subscription_id', $subscriptionId);
                }
            })
            ->first();

        return $subscription?->user;
    }

    private function orphan(string $driver, WebhookEvent $event): void
    {
        try {
            BillingEvent::create([
                'driver' => $driver,
                'gateway_event_id' => $event->id,
                'type' => $event->type,
                'user_id' => null,
                'gateway_customer_id' => $event->customerId,
                'gateway_subscription_id' => $event->subscriptionId,
                'amount_cents' => $event->amountCents,
                'currency' => $event->currency,
                'period_end' => $event->periodEnd,
                'payload' => $event->payload,
                'reason' => 'No subscription matched this processor id',
                'occurred_at' => $event->occurredAt ?? now(),
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (! $this->isDuplicate($e)) {
                throw $e;
            }
        }
    }

    private function isDuplicate(QueryException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'unique');
    }
}
