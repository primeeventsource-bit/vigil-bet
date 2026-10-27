<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Plans
|--------------------------------------------------------------------------
|
| Prices, limits and features live here and not in a Blade file, so what
| the pricing page advertises and what the product enforces come from one
| source and cannot drift apart.
|
| Money in integer cents, everywhere, always.
|
| `:brand` in any string is replaced with config('brand.name') when it is
| rendered. The product name lives in one place, and a tier called
| "Vigil Pro" written out here would survive a rebrand and quietly keep
| saying the old name on the pricing page.
|
| Entitlement enforcement runs through the single `Entitlements` service,
| checked server-side. Nothing is on sale yet: no merchant processor has
| approved the business model, PAYMENT_DRIVER is `none`, and the gateway
| refuses every call until one does.
|
| THE FREE TIER IS NOT SOLD AND MUST NOT BE REMOVED.
|
| Entitlements::ANONYMOUS_TIER is 'free', and tier() falls back to it for
| every signed-out visitor and every account without a subscription row -
| which is every account, since registration creates none. Deleting the
| key would not "remove the free plan", it would leave the homepage, the
| opinions page and the picks feed resolving to a tier that does not
| exist. It is the floor the product stands on, not a product.
|
| What is sold is the three below it. `sellable()` is the list with a
| price, which is what the pricing page, the checkout and the admin tier
| picker all read - so the floor stays invisible to a customer without
| being absent from the code.
|
*/

return [

    'currency' => 'USD',

    'trial_days' => (int) env('VIGIL_TRIAL_DAYS', 7),

    /*
     * Tiers that no longer exist, mapped to the one that replaced them.
     *
     * subscriptions.tier is a free-form string with no foreign key, and
     * billing_events is append-only - the history genuinely contains
     * 'daily' and always will. Entitlements reads this before deciding a
     * tier is unknown, so an old row grants what it was sold rather than
     * silently degrading its owner to free.
     */
    'renamed' => [
        'daily' => 'core',
    ],

    'tiers' => [

        'free' => [
            'name' => 'Free',
            'blurb' => 'Try it.',
            'monthly_cents' => 0,
            'yearly_cents' => 0,
            'features' => [
                'Odds board for today: moneyline, spread and total',
                'One :brand Read a day, on a pick or a pass',
                'Bet tracker, up to 25 bets',
                'Parlay grader, three a day',
            ],
            'grants' => [
                'board.today',
                'read.view',
                'bets.track',
                'parlay.grade',
            ],
            'limits' => ['reads_per_day' => 1, 'tracked_bets' => 25, 'parlays_per_day' => 3],
        ],

        'core' => [
            'name' => ':brand Core',
            'blurb' => 'For the daily answer.',
            'monthly_cents' => 2999,
            // Two months free against the monthly price, which is the
            // discount the old yearly figures implied and is worth
            // stating rather than leaving to arithmetic.
            'yearly_cents' => 29990,
            'features' => [
                'Every pick from a proven market, with a :brand Read',
                'The near misses too, and why each was declined',
                'Unlimited parlay grader',
                'Bet tracker with win/loss and ROI',
                'Alerts when a pick posts',
            ],
            'grants' => [
                'board.today',
                'read.view',
                'bets.track',
                'parlay.grade',
                'card.daily',
                'bets.roi',
                'alerts',
            ],
            'limits' => ['reads_per_day' => null, 'tracked_bets' => null, 'parlays_per_day' => null],
        ],

        'pro' => [
            'name' => ':brand Pro',
            'blurb' => 'For the whole board.',
            'monthly_cents' => 5999,
            'yearly_cents' => 59990,
            'highlight' => true,
            'features' => [
                'Everything in Core',
                'The full value board: every pick and every pass, with edge and EV',
                'Line shopping across every tracked book',
                'Player props board',
                'Closing line value on your own bets',
                'Arbitrage and middle finders',
                'Model Lab: which markets are proven, and the gate each was measured against',
                'Bankroll and Kelly sizing, with loss limits that do not negotiate',
            ],
            'grants' => [
                'board.today',
                'read.view',
                'bets.track',
                'parlay.grade',
                'card.daily',
                'bets.roi',
                'alerts',
                'board.full',
                'lineshopping',
                'props',
                'bets.clv',
                'tools.crossbook',
                'modellab',
                'books.custom',
                'bankroll',
            ],
            'limits' => ['reads_per_day' => null, 'tracked_bets' => null, 'parlays_per_day' => null],
        ],

        /*
         * Elite had to be able to say something true.
         *
         * Pro already granted all fifteen feature keys that existed, so
         * a tier above it would have been a higher price for the same
         * product - and inventing a bullet to justify the gap is the
         * exact thing the brief forbids.
         *
         * The two grants below are real pages built in this same change
         * and shown to nobody below this tier: the player ratings the
         * engine has been computing since launch and never displayed
         * (5,289 rows at the time of writing), and the prop projections
         * it stores and settles and likewise never showed (9,691 rows).
         * Both were finished work with no surface.
         *
         * No `highlight`. Pro carries it, because recommending the most
         * expensive plan is not a recommendation.
         */
        'elite' => [
            'name' => ':brand Elite',
            'blurb' => 'For the player level.',
            'monthly_cents' => 9999,
            'yearly_cents' => 99990,
            'features' => [
                'Everything in Pro',
                'Player IQ: a 0-99 rating for every tracked player, against their own position',
                'Projected player props, not just the price each book is posting',
                'Depth charts and availability behind every projection',
                'The highest :brand Read allowance',
            ],
            'grants' => [
                'board.today',
                'read.view',
                'bets.track',
                'parlay.grade',
                'card.daily',
                'bets.roi',
                'alerts',
                'board.full',
                'lineshopping',
                'props',
                'bets.clv',
                'tools.crossbook',
                'modellab',
                'books.custom',
                'bankroll',
                'playeriq',
                'props.projected',
            ],
            'limits' => ['reads_per_day' => null, 'tracked_bets' => null, 'parlays_per_day' => null],
        ],

    ],
];
