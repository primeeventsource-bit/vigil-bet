<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Translates a tier and an interval into what a processor wants.
 *
 * Shared by every driver, because the mapping is the same question in
 * each: "the customer asked for Pro yearly, what is that called over
 * there, and what does it cost?". The price comes from
 * `config/plans.php`, so the pricing page and the charge cannot disagree.
 */
final class PlanCatalogue
{
    public const INTERVALS = ['monthly', 'yearly'];

    public function __construct(private readonly string $driver) {}

    /** Tiers that can actually be bought. Free is not one of them. */
    public static function sellableTiers(): array
    {
        return array_values(array_filter(
            array_keys((array) config('plans.tiers')),
            fn (string $tier) => (int) config('plans.tiers.'.$tier.'.monthly_cents', 0) > 0,
        ));
    }

    public static function isSellable(string $tier): bool
    {
        return in_array($tier, self::sellableTiers(), true);
    }

    public static function isInterval(string $interval): bool
    {
        return in_array($interval, self::INTERVALS, true);
    }

    /** What we will charge, in cents, read from the published prices. */
    public static function amountCents(string $tier, string $interval): int
    {
        return (int) config(
            'plans.tiers.'.$tier.'.'.($interval === 'yearly' ? 'yearly_cents' : 'monthly_cents'),
            0,
        );
    }

    /**
     * The processor's own id for this plan.
     *
     * @throws PaymentException when the plan was never created over there
     */
    public function gatewayPlanId(string $tier, string $interval): string
    {
        $this->assertSellable($tier, $interval);

        $id = config("payments.drivers.{$this->driver}.plans.{$tier}.{$interval}");

        if (! is_string($id) || $id === '') {
            // Loud, because the alternative is a subscription created
            // against a plan id of "" that bills nobody anything.
            throw new PaymentException(
                "No {$this->driver} plan id configured for {$tier} {$interval}.",
                'That plan is not available right now.',
            );
        }

        return $id;
    }

    /** @throws PaymentException */
    public function assertSellable(string $tier, string $interval): void
    {
        if (! self::isSellable($tier)) {
            throw new PaymentException(
                "{$tier} is not a tier anybody can buy.",
                'That plan does not exist.',
            );
        }

        if (! self::isInterval($interval)) {
            throw new PaymentException(
                "{$interval} is not a billing interval.",
                'That billing period does not exist.',
            );
        }
    }
}
