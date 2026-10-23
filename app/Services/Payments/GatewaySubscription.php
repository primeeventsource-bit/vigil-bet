<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Carbon\CarbonImmutable;

/**
 * What a processor tells us about a subscription, in our own terms.
 *
 * A value object rather than the processor's array, so that the shape of
 * an NMI response does not become the shape of the application. The whole
 * cost of changing processor is translating into this.
 */
final class GatewaySubscription
{
    public function __construct(
        public readonly string $id,
        public readonly string $tier,
        public readonly string $interval,
        public readonly ?CarbonImmutable $periodEnd = null,
        public readonly ?CarbonImmutable $trialEndsAt = null,
        public readonly int $amountCents = 0,
        public readonly string $currency = 'USD',
    ) {}
}
