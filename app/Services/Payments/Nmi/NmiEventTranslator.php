<?php

declare(strict_types=1);

namespace App\Services\Payments\Nmi;

use App\Models\BillingEvent;
use App\Services\Payments\WebhookEvent;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * NMI's event vocabulary, translated into ours.
 *
 * Separate from the driver because this is the part most likely to be
 * wrong first: event names are the thing a processor adds to without
 * telling anybody. Returning null for an unrecognised type is deliberate
 * - most of what NMI sends is of no interest here, and the alternative
 * is a webhook endpoint that 500s every time NMI ships a feature.
 */
class NmiEventTranslator
{
    /**
     * NMI event type to ours. Everything absent from this map is
     * ignored, on purpose.
     */
    private const MAP = [
        'recurring.subscription.add' => BillingEvent::SUBSCRIBED,
        'recurring.subscription.update' => BillingEvent::PLAN_CHANGED,
        'recurring.subscription.delete' => BillingEvent::CANCEL_SCHEDULED,
        'transaction.sale.success' => BillingEvent::RENEWED,
        'transaction.sale.failure' => BillingEvent::PAYMENT_FAILED,
        'transaction.sale.unknown' => BillingEvent::PAYMENT_FAILED,
        'transaction.refund.success' => BillingEvent::REFUNDED,
    ];

    /**
     * @param  array<string, mixed>  $eventBody
     * @param  array<string, mixed>  $whole
     */
    public function translate(string $id, string $nmiType, array $eventBody, array $whole): ?WebhookEvent
    {
        $type = self::MAP[$nmiType] ?? null;

        if ($type === null) {
            return null;
        }

        $amount = $eventBody['amount'] ?? null;

        return new WebhookEvent(
            id: $id,
            type: $type,
            customerId: $this->string($eventBody['customer_vault_id'] ?? $eventBody['customer_id'] ?? null),
            subscriptionId: $this->string($eventBody['subscription_id'] ?? $eventBody['id'] ?? null),
            // Tier and interval are not NMI's to tell us. They are
            // whatever we recorded when the subscription was created, so
            // the projector reads them from our own history instead of
            // trusting a webhook to name a plan correctly.
            amountCents: $amount === null ? null : (int) round(((float) $amount) * 100),
            currency: $this->string($eventBody['currency'] ?? null) ?? 'USD',
            periodEnd: $this->date($eventBody['next_charge_date'] ?? null),
            occurredAt: $this->date($whole['occurred_at'] ?? $eventBody['created_at'] ?? null) ?? CarbonImmutable::now(),
            payload: $whole,
        );
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : (is_int($value) ? (string) $value : null);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
