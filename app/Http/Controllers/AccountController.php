<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BillingEvent;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Account: plan, billing, history, security.
 *
 * The billing history comes straight out of `billing_events`, which is
 * the same log the subscription is derived from. One source, so what the
 * customer is shown and what the product acts on cannot disagree - and a
 * customer arguing about a charge is looking at the same rows support is.
 */
class AccountController extends Controller
{
    /**
     * Event names as a customer would read them.
     *
     * Deliberately not "subscription.plan_changed". The log speaks our
     * vocabulary because it has to be precise; this page speaks English
     * because somebody has to read it.
     */
    private const LABELS = [
        BillingEvent::SUBSCRIBED => 'Subscription started',
        BillingEvent::RENEWED => 'Payment taken',
        BillingEvent::PLAN_CHANGED => 'Plan changed',
        BillingEvent::CANCEL_SCHEDULED => 'Cancellation requested',
        BillingEvent::RESUMED => 'Subscription resumed',
        BillingEvent::ENDED => 'Subscription ended',
        BillingEvent::PAYMENT_FAILED => 'Payment failed',
        BillingEvent::PAYMENT_RECOVERED => 'Payment recovered',
        BillingEvent::REFUNDED => 'Refunded',
        BillingEvent::DOWNGRADED => 'Moved to the free plan',
    ];

    public function __invoke(Request $request, PaymentGateway $gateway): View
    {
        $user = $request->user();

        return view('app.account', [
            'gatewayConfigured' => $gateway->configured(),
            'gatewayName' => $gateway->name(),
            'portalAvailable' => $gateway->hostsBillingPortal()
                && $user->subscription?->gateway_customer_id !== null,
            // Only what can be bought. Free is not a purchase.
            'sellableTiers' => collect(config('plans.tiers'))
                ->filter(fn (array $tier) => (int) ($tier['monthly_cents'] ?? 0) > 0)
                ->all(),
            'events' => BillingEvent::query()
                ->where('user_id', $user->id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(25)
                ->get(),
            'eventLabels' => self::LABELS,
        ]);
    }
}
