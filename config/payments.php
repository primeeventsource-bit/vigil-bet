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
                'daily' => [
                    'monthly' => env('NMI_PLAN_DAILY_MONTHLY'),
                    'yearly' => env('NMI_PLAN_DAILY_YEARLY'),
                ],
                'pro' => [
                    'monthly' => env('NMI_PLAN_PRO_MONTHLY'),
                    'yearly' => env('NMI_PLAN_PRO_YEARLY'),
                ],
            ],
        ],

        'stripe' => [
            'base_url' => rtrim((string) env('STRIPE_BASE_URL', 'https://api.stripe.com'), '/'),
            'api_key' => env('STRIPE_SECRET'),
            'webhook_key' => env('STRIPE_WEBHOOK_SECRET'),

            'plans' => [
                'daily' => [
                    'monthly' => env('STRIPE_PRICE_DAILY_MONTHLY'),
                    'yearly' => env('STRIPE_PRICE_DAILY_YEARLY'),
                ],
                'pro' => [
                    'monthly' => env('STRIPE_PRICE_PRO_MONTHLY'),
                    'yearly' => env('STRIPE_PRICE_PRO_YEARLY'),
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
     * A trial is granted by us, not by the processor, so it survives a
     * change of processor and cannot be lost in a migration.
     */
    'trial_days' => (int) env('VIGIL_TRIAL_DAYS', 7),

    /*
     * Webhook replay window. NMI's signature carries a nonce rather than
     * a timestamp, so there is no window to enforce there and
     * deduplication by event id is the whole defence; Stripe's does carry
     * one. Either way an event id is only ever accepted once.
     */
    'webhook_tolerance_seconds' => 300,
];
