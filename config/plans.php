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
| Entitlement *enforcement* arrives at step 5, through a single
| `Entitlements` service checked server-side. Until then these feature
| keys describe the plans honestly on the marketing pages and gate
| nothing - which is why nothing is on sale yet.
|
*/

return [

    'currency' => 'USD',

    'trial_days' => (int) env('VIGIL_TRIAL_DAYS', 7),

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
            'limits' => ['reads_per_day' => 1, 'tracked_bets' => 25, 'parlays_per_day' => 3],
        ],

        'daily' => [
            'name' => ':brand Daily',
            'blurb' => 'For the daily answer.',
            'monthly_cents' => 1900,
            'yearly_cents' => 17900,
            'highlight' => true,
            'features' => [
                'Every pick from a proven market, with a :brand Read',
                'The near misses too, and why each was declined',
                'Unlimited parlay grader',
                'Bet tracker with win/loss and ROI',
                'Alerts when a pick posts',
            ],
            'limits' => ['reads_per_day' => null, 'tracked_bets' => null, 'parlays_per_day' => null],
        ],

        'pro' => [
            'name' => ':brand Pro',
            'blurb' => 'For the whole board.',
            'monthly_cents' => 5900,
            'yearly_cents' => 54900,
            'features' => [
                'Everything in Daily',
                'The full value board: every pick and every pass, with edge and EV',
                'Line shopping across every tracked book',
                'Player props board',
                'Closing line value on your own bets',
                'Arbitrage and middle finders',
                'Model Lab: which markets are proven, and the gate each was measured against',
                'Bankroll and Kelly sizing, with loss limits that do not negotiate',
            ],
            'limits' => ['reads_per_day' => null, 'tracked_bets' => null, 'parlays_per_day' => null],
        ],

    ],
];
