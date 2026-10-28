<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payments
|--------------------------------------------------------------------------
|
| Sports-information subscriptions are routinely classed as high risk, and
| Stripe's prohibited-business list names sports forecasting with a
| monetary or material prize. Vigil may therefore have to change processor
| at short notice, possibly under pressure, so §5 asks that the processor
| can change without touching application code.
|
| That is what this file is. Nothing above the driver layer knows which
| processor is in use: the driver is resolved from PAYMENT_DRIVER through
| one interface, and §13 is tested against it - switching between `nmi`
| and `stripe` must require no code changes.
|
| Money in integer cents, everywhere, always.
|
*/

return [

    /*
     * Which processor is live. `none` is the honest default: with no
     * merchant account there is no processor, and a driver that quietly
     * pretended to take a card would be the worst possible bug here.
     */
    'driver' => env('PAYMENT_DRIVER', 'none'),

    /*
     * Stripe is behind a flag because it may not accept this business at
     * all. The driver exists so the switch is a config change rather than
     * a weekend, not because it is expected to be used.
     */
    'stripe_enabled' => (bool) env('PAYMENT_STRIPE_ENABLED', false),

    'drivers' => [

        'nmi' => [
            /*
             * Set to the sandbox host while testing:
             * https://sandbox.nmi.com/api
             */
            'base_url' => rtrim((string) env('NMI_BASE_URL', 'https://secure.nmi.com/api'), '/'),

            // REST v5 takes the key raw in the Authorization header.
            'api_key' => env('NMI_API_KEY'),

            // Signs inbound webhooks. Separate secret, separate rotation.
            'webhook_key' => env('NMI_WEBHOOK_SIGNING_KEY'),

            /*
             * Gateway-side plan ids, created once in the merchant portal.
             * Nested by tier then interval so one lookup serves both the
             * checkout and a plan change.
             */
            'plans' => [
                'core' => [
                    'monthly' => env('NMI_PLAN_CORE_MONTHLY'),
                    'yearly' => env('NMI_PLAN_CORE_YEARLY'),
                ],
                'pro' => [
                    'monthly' => env('NMI_PLAN_PRO_MONTHLY'),
                    'yearly' => env('NMI_PLAN_PRO_YEARLY'),
                ],
                'elite' => [
                    'monthly' => env('NMI_PLAN_ELITE_MONTHLY'),
                    'yearly' => env('NMI_PLAN_ELITE_YEARLY'),
                ],
            ],
        ],

        'stripe' => [
            'base_url' => rtrim((string) env('STRIPE_BASE_URL', 'https://api.stripe.com'), '/'),
            'api_key' => env('STRIPE_SECRET'),
            'webhook_key' => env('STRIPE_WEBHOOK_SECRET'),

            'plans' => [
                'core' => [
                    'monthly' => env('STRIPE_PRICE_CORE_MONTHLY'),
                    'yearly' => env('STRIPE_PRICE_CORE_YEARLY'),
                ],
                'pro' => [
                    'monthly' => env('STRIPE_PRICE_PRO_MONTHLY'),
                    'yearly' => env('STRIPE_PRICE_PRO_YEARLY'),
                ],
                'elite' => [
                    'monthly' => env('STRIPE_PRICE_ELITE_MONTHLY'),
                    'yearly' => env('STRIPE_PRICE_ELITE_YEARLY'),
                ],
            ],
        ],

    ],

    /*
     * Dunning. §5: three retries over seven days, an email at each, then
     * a downgrade to Free with the data kept.
     *
     * Kept as a list of day offsets rather than a count and an interval,
     * because the schedule is the policy: somebody reading this should be
     * able to see when a customer gets written to without doing
     * arithmetic.
     */
    'dunning' => [
        'retry_after_days' => [1, 3, 7],
        // What happens at the end of it. Never a deletion: §5 is explicit
        // that the data is kept, and a customer who comes back should
        // find their bets where they left them.
        'then' => 'downgrade_to_free',
    ],

    /*
     * Days of trial to ask a payment processor for, and it is zero.
     *
     * This is NOT the product's free trial. That one is 24 hours, lives
     * on the account rather than on a subscription, takes no card, and
     * cannot become a subscription - which is the point of it. See
     * config/plans.php trial_hours.
     *
     * This value only ever reaches Stripe's `trial_period_days` on a
     * checkout somebody has already given a card for. It is zero
     * because the product promises in writing that nothing converts
     * automatically, and a processor-side trial is exactly a thing that
     * converts automatically.
     */
    'trial_days' => (int) env('VIGIL_PROCESSOR_TRIAL_DAYS', 0),

    /*
     * Webhook replay window. NMI's signature carries a nonce rather than
     * a timestamp, so there is no window to enforce there and
     * deduplication by event id is the whole defence; Stripe's does carry
     * one. Either way an event id is only ever accepted once.
     */
    'webhook_tolerance_seconds' => 300,
];
