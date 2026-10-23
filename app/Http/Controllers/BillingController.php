<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BillingEvent;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PlanCatalogue;
use App\Services\Payments\SubscriptionProjector;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Subscribing, changing plan, cancelling and resuming.
 *
 * Every action here writes a billing event and lets the projector derive
 * the subscription from it. Nothing writes `subscriptions` directly, so
 * the history is complete whether a change came from the processor or
 * from this page.
 *
 * No card data passes through. The browser mints a token against the
 * processor's own hosted field and sends the token; §5 requires that and
 * it is also the only reason this application is not in PCI scope.
 */
class BillingController extends Controller
{
    public function subscribe(
        Request $request,
        PaymentGateway $gateway,
        SubscriptionProjector $projector,
    ): RedirectResponse {
        $data = $request->validate([
            'tier' => ['required', Rule::in(PlanCatalogue::sellableTiers())],
            'interval' => ['required', Rule::in(PlanCatalogue::INTERVALS)],
            // Minted in the browser. Never a card number: a request that
            // carried one would put this server in PCI scope, so the
            // rule is a size limit and a string, and the driver sends it
            // straight back out.
            'payment_token' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        if (! $gateway->configured()) {
            return back()->withErrors([
                'tier' => 'Subscriptions are not on sale yet.',
            ]);
        }

        try {
            $customerId = $user->subscription?->gateway_customer_id
                ?? $gateway->createCustomer($user, $data['payment_token']);

            $subscription = $gateway->createSubscription($customerId, $data['tier'], $data['interval']);
        } catch (PaymentException $e) {
            Log::warning('Subscription failed', [
                'user_id' => $user->id,
                'driver' => $gateway->name(),
                'reason' => $e->getMessage(),
            ]);

            return back()->withErrors(['payment_token' => $e->forCustomer()]);
        }

        $projector->record($user, BillingEvent::SUBSCRIBED, [
            'driver' => $gateway->name(),
            'gateway_customer_id' => $customerId,
            'gateway_subscription_id' => $subscription->id,
            'tier' => $subscription->tier,
            'interval' => $subscription->interval,
            'amount_cents' => $subscription->amountCents,
            'currency' => $subscription->currency,
            'period_end' => $subscription->periodEnd,
            'actor_id' => $user->id,
            'reason' => 'Subscribed from Account',
        ]);

        return redirect()->route('account')
            ->with('status', 'You are on '.$user->refresh()->subscription->describe().'.');
    }

    public function changePlan(
        Request $request,
        PaymentGateway $gateway,
        SubscriptionProjector $projector,
    ): RedirectResponse {
        $data = $request->validate([
            'tier' => ['required', Rule::in(PlanCatalogue::sellableTiers())],
            'interval' => ['required', Rule::in(PlanCatalogue::INTERVALS)],
        ]);

        $user = $request->user();
        $subscription = $user->subscription;

        if ($subscription?->gateway_subscription_id === null) {
            return back()->withErrors(['tier' => 'There is no subscription to change.']);
        }

        try {
            $changed = $gateway->changePlan(
                $subscription->gateway_subscription_id,
                $data['tier'],
                $data['interval'],
            );
        } catch (PaymentException $e) {
            return back()->withErrors(['tier' => $e->forCustomer()]);
        }

        $projector->record($user, BillingEvent::PLAN_CHANGED, [
            'driver' => $gateway->name(),
            'gateway_subscription_id' => $changed->id,
            'tier' => $changed->tier,
            'interval' => $changed->interval,
            'amount_cents' => $changed->amountCents,
            'period_end' => $changed->periodEnd,
            'actor_id' => $user->id,
            'reason' => 'Plan changed from Account',
        ]);

        return redirect()->route('account')
            ->with('status', 'You are on '.$user->refresh()->subscription->describe().'.');
    }

    /**
     * Cancel.
     *
     * §4: one click, access kept until period end, no dark patterns. So
     * there is no confirmation funnel, no "are you sure", no offer of a
     * discount in the way, and nothing is taken away today. One POST
     * from one button.
     *
     * §13 is tested against both halves of that.
     */
    public function cancel(
        Request $request,
        PaymentGateway $gateway,
        SubscriptionProjector $projector,
    ): RedirectResponse {
        $user = $request->user();
        $subscription = $user->subscription;

        if ($subscription?->gateway_subscription_id === null) {
            return back()->withErrors(['cancel' => 'There is no subscription to cancel.']);
        }

        try {
            $gateway->cancelAtPeriodEnd($subscription->gateway_subscription_id);
        } catch (PaymentException $e) {
            /*
             * The processor refused. The cancellation is recorded
             * anyway, because the customer has asked to leave and that
             * is a fact about them rather than about the processor. A
             * cancellation lost to an HTTP error is the kind of thing
             * that ends up in a complaint, correctly.
             */
            Log::error('Cancellation could not be sent to the processor', [
                'user_id' => $user->id,
                'driver' => $gateway->name(),
                'reason' => $e->getMessage(),
            ]);
        }

        $projector->record($user, BillingEvent::CANCEL_SCHEDULED, [
            'driver' => $gateway->name(),
            'gateway_subscription_id' => $subscription->gateway_subscription_id,
            'tier' => $subscription->tier,
            'interval' => $subscription->interval,
            'period_end' => $subscription->current_period_end,
            'actor_id' => $user->id,
            'reason' => 'Cancelled from Account',
        ]);

        $until = $subscription->current_period_end?->toFormattedDateString();

        return redirect()->route('account')->with(
            'status',
            $until === null
                ? 'Your subscription will not renew.'
                : 'Your subscription will not renew. You keep everything until '.$until.'.',
        );
    }

    public function resume(
        Request $request,
        PaymentGateway $gateway,
        SubscriptionProjector $projector,
    ): RedirectResponse {
        $user = $request->user();
        $subscription = $user->subscription;

        if ($subscription?->gateway_subscription_id === null) {
            return back()->withErrors(['resume' => 'There is no subscription to resume.']);
        }

        try {
            $gateway->resume($subscription->gateway_subscription_id);
        } catch (PaymentException $e) {
            // Some processors cannot resume at all - NMI deletes rather
            // than schedules - so this says so instead of pretending.
            return back()->withErrors(['resume' => $e->forCustomer()]);
        }

        $projector->record($user, BillingEvent::RESUMED, [
            'driver' => $gateway->name(),
            'gateway_subscription_id' => $subscription->gateway_subscription_id,
            'tier' => $subscription->tier,
            'interval' => $subscription->interval,
            'period_end' => $subscription->current_period_end,
            'actor_id' => $user->id,
            'reason' => 'Resumed from Account',
        ]);

        return redirect()->route('account')->with('status', 'Your subscription will renew as normal.');
    }

    /**
     * Send the customer to the processor's own card-management page.
     *
     * Not every processor has one; §5 marks `customerPortalUrl` nullable
     * for exactly that reason, and this says so rather than linking to
     * nowhere.
     */
    public function portal(Request $request, PaymentGateway $gateway): RedirectResponse
    {
        $customerId = $request->user()->subscription?->gateway_customer_id;

        if ($customerId === null) {
            return back()->withErrors(['portal' => 'There is no billing account yet.']);
        }

        try {
            $url = $gateway->customerPortalUrl($customerId);
        } catch (PaymentException $e) {
            return back()->withErrors(['portal' => $e->forCustomer()]);
        }

        if ($url === null) {
            return back()->withErrors([
                'portal' => 'This processor has no billing page. Email support to change your card.',
            ]);
        }

        Audit::write('billing.portal_opened', $request->user(), null, null, 'Customer opened the processor portal');

        return redirect()->away($url);
    }
}
