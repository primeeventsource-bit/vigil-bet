<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingEvent;
use App\Models\EntitlementOverride;
use App\Models\User;
use App\Services\Admin\FeatureFlags;
use App\Services\Entitlements\Entitlements;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\SubscriptionProjector;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The writes.
 *
 * Every one of them is audited, which §11 asks for, and every one of them
 * requires a typed reason. That second rule is the one that matters: an
 * entitlement granted with no note is indistinguishable from a bug a
 * month later, and "support said it was fine" is not a record of
 * anything.
 *
 * Deliberately few. An admin area that can change anything is an admin
 * area that eventually does - so there is no way here to edit a bet, a
 * published pick, a billing event or an audit entry. Those tables are
 * append-only in the database and this controller could not write them
 * if it tried.
 */
class AdminActionController extends Controller
{
    /**
     * Grant or withdraw one feature for one customer.
     *
     * Beats the plan in both directions, which is the point of having
     * an override: support can grant a feature while a billing problem
     * is sorted out, and withdraw one without editing a tier.
     */
    public function override(Request $request, User $user, Entitlements $entitlements): RedirectResponse
    {
        $data = $request->validate([
            'feature' => ['required', Rule::in($entitlements->allFeatures())],
            'granted' => ['required', 'boolean'],
            // Required, and at a length that cannot be satisfied by a
            // full stop. A note nobody can read is not a note.
            'reason' => ['required', 'string', 'min:8', 'max:200'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $before = EntitlementOverride::query()
            ->where('user_id', $user->id)
            ->where('feature', $data['feature'])
            ->first();

        $override = EntitlementOverride::updateOrCreate(
            ['user_id' => $user->id, 'feature' => $data['feature']],
            [
                'granted' => (bool) $data['granted'],
                'reason' => $data['reason'],
                'granted_by' => $request->user()->id,
                'expires_at' => $data['expires_at'] ?? null,
            ],
        );

        /*
         * The service memoizes overrides for the life of a request, so
         * anything that checks an entitlement after this write - this
         * page rendering its own result, a redirect handled in the same
         * process - would otherwise see the value from before it.
         */
        $entitlements->forget($user);

        Audit::write(
            'entitlement.'.($data['granted'] ? 'granted' : 'withdrawn'),
            $user,
            $before === null ? null : $before->only(['feature', 'granted', 'expires_at']),
            $override->only(['feature', 'granted', 'expires_at']),
            $data['reason'],
        );

        return back()->with('status', 'Override saved.');
    }

    public function removeOverride(Request $request, User $user, Entitlements $entitlements): RedirectResponse
    {
        $data = $request->validate([
            'feature' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:8', 'max:200'],
        ]);

        $override = EntitlementOverride::query()
            ->where('user_id', $user->id)
            ->where('feature', $data['feature'])
            ->first();

        if ($override === null) {
            return back()->withErrors(['feature' => 'There is no override on that feature.']);
        }

        $before = $override->only(['feature', 'granted', 'expires_at']);
        $override->delete();

        $entitlements->forget($user);

        Audit::write('entitlement.override_removed', $user, $before, null, $data['reason']);

        return back()->with('status', 'Override removed. The plan decides again.');
    }

    /**
     * Change a customer's tier by hand.
     *
     * Recorded as a billing event rather than written straight onto the
     * subscription, so the history stays complete and the projection is
     * still derived from the log. A tier set by an admin and a tier set
     * by a payment should be the same kind of fact.
     */
    public function tier(
        Request $request,
        User $user,
        SubscriptionProjector $projector,
    ): RedirectResponse {
        $data = $request->validate([
            'tier' => ['required', Rule::in(array_keys((array) config('plans.tiers')))],
            'reason' => ['required', 'string', 'min:8', 'max:200'],
        ]);

        $before = $user->subscription?->tier;

        $projector->record($user, BillingEvent::PLAN_CHANGED, [
            'driver' => $user->subscription?->gateway ?? 'none',
            'tier' => $data['tier'],
            'interval' => $user->subscription?->interval,
            'gateway_customer_id' => $user->subscription?->gateway_customer_id,
            'gateway_subscription_id' => $user->subscription?->gateway_subscription_id,
            'period_end' => $user->subscription?->current_period_end,
            'actor_id' => $request->user()->id,
            'reason' => $data['reason'],
        ]);

        Audit::write('tier.changed', $user, ['tier' => $before], ['tier' => $data['tier']], $data['reason']);

        return back()->with('status', 'Tier changed to '.$data['tier'].'.');
    }

    /**
     * Refund a charge.
     *
     * The only action here that moves money, and the only one that can
     * fail for a reason outside this application. The refund is recorded
     * only if the processor accepted it - recording it first would show
     * a customer money back that nobody sent.
     */
    public function refund(
        Request $request,
        PaymentGateway $gateway,
        SubscriptionProjector $projector,
    ): RedirectResponse {
        $data = $request->validate([
            'billing_event_id' => ['required', 'integer', 'exists:billing_events,id'],
            'reason' => ['required', 'string', 'min:8', 'max:200'],
            // Partial refunds are allowed. Blank means the whole charge.
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $event = BillingEvent::findOrFail($data['billing_event_id']);

        if ($event->amount_cents === null || $event->amount_cents <= 0) {
            return back()->withErrors(['refund' => 'That event did not charge anything.']);
        }

        $chargeId = $event->payload['charge_id']
            ?? $event->payload['transaction_id']
            ?? $event->payload['event_body']['transaction_id']
            ?? null;

        if (! is_string($chargeId) || $chargeId === '') {
            /*
             * No processor id in the stored payload, so there is nothing
             * to refund against. Said plainly rather than guessing at a
             * reference - a refund sent against the wrong charge is
             * worse than one that did not happen.
             */
            return back()->withErrors([
                'refund' => 'That event carries no processor charge id, so it cannot be refunded from here.',
            ]);
        }

        $cents = isset($data['amount'])
            ? (int) round(((float) $data['amount']) * 100)
            : (int) $event->amount_cents;

        if ($cents > (int) $event->amount_cents) {
            return back()->withErrors(['refund' => 'That is more than the original charge.']);
        }

        try {
            $gateway->refund($chargeId, $cents);
        } catch (PaymentException $e) {
            return back()->withErrors(['refund' => $e->forCustomer().' ('.$e->getMessage().')']);
        }

        if ($event->user_id !== null) {
            $projector->record(User::findOrFail($event->user_id), BillingEvent::REFUNDED, [
                'driver' => $gateway->name(),
                'gateway_customer_id' => $event->gateway_customer_id,
                'gateway_subscription_id' => $event->gateway_subscription_id,
                'amount_cents' => $cents,
                'currency' => $event->currency,
                'actor_id' => $request->user()->id,
                'reason' => $data['reason'],
            ]);
        }

        Audit::write('billing.refunded', $event,
            ['amount_cents' => $event->amount_cents],
            ['refunded_cents' => $cents],
            $data['reason']);

        return back()->with('status', 'Refunded $'.number_format($cents / 100, 2).'.');
    }

    /** Throw a feature flag. */
    public function flag(Request $request, FeatureFlags $flags): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:8', 'max:200'],
        ]);

        try {
            $flags->set($data['key'], (bool) $data['enabled'], $request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['key' => $e->getMessage()]);
        }

        return back()->with('status', 'Flag '.$data['key'].' is now '
            .($data['enabled'] ? 'on' : 'off').'.');
    }
}
