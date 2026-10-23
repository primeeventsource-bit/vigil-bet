<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Carbon\CarbonImmutable;

/**
 * A verified webhook, translated out of the processor's vocabulary.
 *
 * `id` is the processor's event id and it is what makes delivery
 * idempotent: it goes into a unique column, so the second copy of an
 * event fails at the database rather than in a handler.
 */
final class WebhookEvent
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $customerId = null,
        public readonly ?string $subscriptionId = null,
        public readonly ?string $tier = null,
        public readonly ?string $interval = null,
        public readonly ?int $amountCents = null,
        public readonly ?string $currency = null,
        public readonly ?CarbonImmutable $periodEnd = null,
        public readonly ?CarbonImmutable $occurredAt = null,
        public readonly array $payload = [],
    ) {}
}
