<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\BillingEvent;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Derives a customer's subscription from the billing events.
 *
 * §5: "Subscription state is derived from billing events." Not a
 * stylistic preference. Billing disputes are arguments about history -
 * "we cancelled on the third", "you charged us twice" - and a row that
 * has been updated in place eleven times cannot answer them. An event log
 * can, and a projection can always be thrown away and rebuilt from it.
 *
 * So `subscriptions` is a cache of the answer, never the answer. This
 * class is the only thing allowed to write it.
 */
class SubscriptionProjector
{
    /**
     * Record something that happened, then rebuild the projection.
     *
     * One transaction, because a written event with no projection would
     * leave a customer who has paid looking unpaid until somebody
     * noticed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(User $user, string $type, array $attributes = []): BillingEvent
    {
        return DB::transaction(function () use ($user, $type, $attributes): BillingEvent {
            $event = BillingEvent::create([
                'driver' => $attributes['driver'] ?? app(PaymentGateway::class)->name(),
                'gateway_event_id' => $attributes['gateway_event_id'] ?? null,
                'type' => $type,
                'user_id' => $user->id,
                'gateway_customer_id' => $attributes['gateway_customer_id'] ?? null,
                'gateway_subscription_id' => $attributes['gateway_subscription_id'] ?? null,
                'tier' => $attributes['tier'] ?? null,
                'interval' => $attributes['interval'] ?? null,
                'amount_cents' => $attributes['amount_cents'] ?? null,
                'currency' => $attributes['currency'] ?? null,
                'period_end' => $attributes['period_end'] ?? null,
                'payload' => $attributes['payload'] ?? null,
                'actor_id' => $attributes['actor_id'] ?? null,
                'reason' => $attributes['reason'] ?? null,
                'occurred_at' => $attributes['occurred_at'] ?? now(),
                'created_at' => now(),
            ]);

            $this->rebuild($user);

            return $event;
        });
    }

    /**
     * Replay every event for this customer and write the result.
     *
     * Deliberately a full replay rather than an incremental update. It is
     * the same code path whether an event has just arrived or the whole
     * table is being rebuilt after a bug, which means the recovery path
     * is the path that runs every day and cannot rot unnoticed.
     */
    public function rebuild(User $user): ?Subscription
    {
        $events = BillingEvent::query()->timeline($user->id)->get();

        if ($events->isEmpty()) {
            return $user->subscription;
        }

        $state = $this->startingState($user, $events);

        foreach ($events as $event) {
            $state = $this->apply($state, $event);
        }

        $state['source'] = 'billing_events';
        $state['last_billing_event_id'] = $events->last()->id;

        return Subscription::updateOrCreate(['user_id' => $user->id], $state);
    }

    /**
     * Where a replay starts from.
     *
     * Normally nothing: the log opens with a subscription being started
     * and every later event is folded onto that, which is what makes the
     * projection throw-away-and-rebuild.
     *
     * The exception is a log with no `subscription.started` in it, which
     * means the log cannot be the whole story - a plan granted by the
     * owner command, or later by an admin, exists as a row and never as
     * an event. Replaying from empty would read that customer's first
     * failed payment as proof they were never subscribed and quietly
     * drop them to Free. So an incomplete log starts from what is
     * already there, and the events are folded onto it.
     *
     * @param  Collection<int, BillingEvent>  $events
     * @return array<string, mixed>
     */
    private function startingState(User $user, $events): array
    {
        $empty = [
            'tier' => 'free',
            'interval' => null,
            'status' => 'none',
            'trial_ends_at' => null,
            'current_period_end' => null,
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
            'gateway' => null,
            'gateway_customer_id' => null,
            'gateway_subscription_id' => null,
        ];

        $existing = $user->subscription;

        if ($existing === null || $events->contains(fn (BillingEvent $e) => $e->type === BillingEvent::SUBSCRIBED)) {
            return $empty;
        }

        return array_merge($empty, $existing->only(array_keys($empty)));
    }

    /**
     * Fold one event into the running state.
     *
     * Every arm is written so that a missing field leaves the previous
     * value alone. A processor that sends a renewal without naming the
     * plan must not be able to move a customer to a tier of null.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function apply(array $state, BillingEvent $event): array
    {
        $state['gateway'] = $event->driver ?: $state['gateway'];
        $state['gateway_customer_id'] = $event->gateway_customer_id ?: $state['gateway_customer_id'];
        $state['gateway_subscription_id'] = $event->gateway_subscription_id ?: $state['gateway_subscription_id'];

        $tier = $event->tier ?: null;
        $interval = $event->interval ?: null;
        $periodEnd = $event->period_end;

        return match ($event->type) {
            BillingEvent::SUBSCRIBED => array_merge($state, [
                'tier' => $tier ?? $state['tier'],
                'interval' => $interval ?? $state['interval'],
                'status' => 'active',
                'current_period_end' => $periodEnd ?? $state['current_period_end'],
                // A new subscription clears a previous cancellation.
                // Otherwise a customer who left and came back would be
                // sold a plan that was already scheduled to stop.
                'cancel_at_period_end' => false,
                'cancelled_at' => null,
            ]),

            BillingEvent::RENEWED, BillingEvent::PAYMENT_RECOVERED => array_merge($state, [
                'status' => 'active',
                'current_period_end' => $periodEnd ?? $state['current_period_end'],
                'tier' => $tier ?? $state['tier'],
            ]),

            BillingEvent::PLAN_CHANGED => array_merge($state, [
                'tier' => $tier ?? $state['tier'],
                'interval' => $interval ?? $state['interval'],
                'current_period_end' => $periodEnd ?? $state['current_period_end'],
            ]),

            /*
             * Scheduled, not done. §4: cancelling takes effect at period
             * end, and the status stays `active` because that is the
             * truth - the customer has paid for time they have not yet
             * used. Subscription::effectiveTier() is what eventually
             * drops them, from the date, without anybody running a job.
             */
            BillingEvent::CANCEL_SCHEDULED => array_merge($state, [
                'cancel_at_period_end' => true,
                'cancelled_at' => $event->occurred_at,
                'current_period_end' => $periodEnd ?? $state['current_period_end'],
            ]),

            BillingEvent::RESUMED => array_merge($state, [
                'cancel_at_period_end' => false,
                'cancelled_at' => null,
                'status' => 'active',
            ]),

            BillingEvent::ENDED => array_merge($state, [
                'status' => 'cancelled',
                'cancelled_at' => $state['cancelled_at'] ?? $event->occurred_at,
                'current_period_end' => $periodEnd ?? $state['current_period_end'],
            ]),

            BillingEvent::PAYMENT_FAILED => array_merge($state, [
                // Past due, not cancelled. Dunning gets three attempts
                // over seven days before anything is taken away, and
                // during those the customer keeps their tier.
                'status' => 'past_due',
            ]),

            /*
             * The end of dunning. Tier goes to free and the period end
             * is cleared, so effectiveTier() has nothing left to keep
             * them on. The data stays: §5 is explicit, and a customer
             * who comes back should find their bets where they left them.
             */
            BillingEvent::DOWNGRADED => array_merge($state, [
                'tier' => 'free',
                'interval' => null,
                'status' => 'cancelled',
                'current_period_end' => null,
                'cancel_at_period_end' => false,
                'cancelled_at' => $state['cancelled_at'] ?? $event->occurred_at,
            ]),

            // Money went back. That is not a change of access, and
            // treating it as one would cut off a customer we had just
            // apologised to.
            default => $state,
        };
    }

    /**
     * How many payment failures since the last thing that went right.
     *
     * Read from the log rather than kept as a counter, because a counter
     * is a thing that can be wrong. Dunning asks this to decide whether
     * it is on attempt one or attempt three.
     */
    public function failuresSinceLastSuccess(User $user): int
    {
        $events = BillingEvent::query()->timeline($user->id)->get();

        $failures = 0;

        foreach ($events as $event) {
            $failures = match ($event->type) {
                BillingEvent::PAYMENT_FAILED => $failures + 1,
                BillingEvent::RENEWED,
                BillingEvent::PAYMENT_RECOVERED,
                BillingEvent::SUBSCRIBED,
                BillingEvent::DOWNGRADED => 0,
                default => $failures,
            };
        }

        return $failures;
    }

    /** When the current run of failures started, or null if there is none. */
    public function firstFailureAt(User $user): ?Carbon
    {
        $events = BillingEvent::query()->timeline($user->id)->get();

        $first = null;

        foreach ($events as $event) {
            match ($event->type) {
                BillingEvent::PAYMENT_FAILED => $first ??= $event->occurred_at,
                BillingEvent::RENEWED,
                BillingEvent::PAYMENT_RECOVERED,
                BillingEvent::SUBSCRIBED,
                BillingEvent::DOWNGRADED => $first = null,
                default => null,
            };
        }

        return $first;
    }
}
