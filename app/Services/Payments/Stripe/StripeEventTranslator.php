<?php

declare(strict_types=1);

namespace App\Services\Payments\Stripe;

use App\Models\BillingEvent;
use App\Services\Payments\WebhookEvent;
use Carbon\CarbonImmutable;

/** Stripe's event vocabulary, translated into ours. */
class StripeEventTranslator
{
    private const MAP = [
        'customer.subscription.created' => BillingEvent::SUBSCRIBED,
        'customer.subscription.updated' => BillingEvent::PLAN_CHANGED,
        'customer.subscription.deleted' => BillingEvent::ENDED,
        'invoice.payment_succeeded' => BillingEvent::RENEWED,
        'invoice.payment_failed' => BillingEvent::PAYMENT_FAILED,
        'charge.refunded' => BillingEvent::REFUNDED,
    ];

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $whole
     */
    public function translate(string $id, string $stripeType, array $object, array $whole): ?WebhookEvent
    {
        $type = self::MAP[$stripeType] ?? null;

        if ($type === null) {
            return null;
        }

        /*
         * An update that only sets cancel_at_period_end is a
         * cancellation, not a plan change. Reading it as the latter
         * would leave the account looking active right up to the day it
         * stopped working.
         */
        if ($type === BillingEvent::PLAN_CHANGED && ($object['cancel_at_period_end'] ?? false)) {
            $type = BillingEvent::CANCEL_SCHEDULED;
        }

        return new WebhookEvent(
            id: $id,
            type: $type,
            customerId: $this->string($object['customer'] ?? null),
            subscriptionId: $this->string($object['subscription'] ?? $object['id'] ?? null),
            amountCents: $this->int($object['amount_paid'] ?? $object['amount'] ?? null),
            currency: strtoupper((string) ($object['currency'] ?? 'usd')),
            periodEnd: $this->date($object['current_period_end'] ?? $object['lines']['data'][0]['period']['end'] ?? null),
            occurredAt: $this->date($whole['created'] ?? null) ?? CarbonImmutable::now(),
            payload: $whole,
        );
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function int(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? CarbonImmutable::createFromTimestampUTC((int) $value)
            : null;
    }
}
