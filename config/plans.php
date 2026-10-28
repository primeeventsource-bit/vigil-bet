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
| THREE UNSOLD TIERS, AND WHY THERE ARE THREE.
|
| `free` used to be one key doing three jobs: the tier a signed-out
| visitor resolved to, the tier a registered account resolved to because
| registration creates no subscription row, and the tier somebody fell
| back to when a subscription lapsed. One name, three meanings, and no
| way for any code to tell which one it was holding.
|
| The 24-hour trial made that untenable, because "has not signed up",
| "is on the trial" and "the trial is over" have to be different answers
| and the first and third were the same key. So:
|
|   guest    - signed out, or registered and not yet activated. Grants
|              nothing, which costs nothing: not one public page in this
|              product performs an entitlement check. The marketing
|              views contain zero @can.
|   trial    - 24 hours from activation. Everything Pro grants, capped.
|   expired  - the trial is over and nothing was bought. The account is
|              alive and its own history is readable; the product is not.
|
| None of the three has a price, so none appears in a checkout, a
| pricing card or the admin tier picker - that list is sellableTiers(),
| which is everything with monthly_cents above zero.
|
*/

return [

    'currency' => 'USD',

    /*
     * Tiers that no longer exist, mapped to the one that replaced them.
     *
     * subscriptions.tier is a free-form string with no foreign key, and
     * billing_events is append-only - the history genuinely contains
     * 'daily' and 'free' and always will. Entitlements reads this before
     * deciding a tier is unknown, so an old row grants what it was sold
     * rather than silently degrading its owner.
     *
     * `free` maps to `guest` rather than to `trial`: a subscription row
     * written before the trial existed is not evidence that anybody
     * activated one, and resolving it as a trial would hand 24 hours of
     * Pro to every lapsed account on the day this shipped.
     */
    'renamed' => [
        'daily' => 'core',
        'free' => 'guest',
    ],

    /*
     * How long a trial lasts, in hours rather than days.
     *
     * Hours because the promise is "expires exactly 72 hours after
     * activation", and days cannot express that: a day boundary moves
     * with a timezone and an activation at 23:50 would lose most of
     * its first one.
     *
     * The old `trial_days` key is gone. It defaulted to 7, was read by
     * exactly one line of Blade advertising "a 7-day free trial on the
     * paid tiers when they open", and that sentence became false the
     * moment this existed. A key whose only reader is a promise the
     * product does not keep is worse than no key.
     */
    'trial_hours' => (int) env('VIGIL_TRIAL_HOURS', 72),

    'tiers' => [

        /*
         * Signed out, or signed in and not yet started.
         *
         * Grants nothing, and that costs nothing: not one public page in
         * this product consults an entitlement. The marketing views
         * contain no @can, no Gate:: and no auth() beyond a sign-in
         * button. The homepage picks feed, /opinions and /record are
         * rendered from queries that never ask what tier is reading
         * them, which is what makes the public record public.
         */
        'guest' => [
            'name' => 'Not started',
            'blurb' => 'Create an account to begin.',
            'monthly_cents' => 0,
            'yearly_cents' => 0,
            'features' => [],
            'grants' => [],
            'limits' => ['reads_per_day' => 0, 'prop_analyses_per_day' => 0, 'tracked_bets' => 0, 'parlays_per_day' => 0],
        ],

        /*
         * Seventy-two hours, and what it shows has to be worth judging.
         *
         * Everything Pro grants, plus Player IQ, because the features
         * most likely to convert somebody are the ones a cheaper
         * demonstration would hide. Capped, because a trial is a sample
         * and the caps are what keep it one.
         *
         * `props.projected` is the one thing held back, and it is the
         * only thing Elite still sells that a trialist will not have
         * seen. That is a deliberate trade and not an oversight: the
         * projections are the engine's live output on games that have
         * not happened, 9,739 of them, and three days is ample time to
         * take a copy.
         */
        'trial' => [
            'name' => 'Free trial',
            'blurb' => '72 hours, no card.',
            'monthly_cents' => 0,
            'yearly_cents' => 0,
            'features' => [
                "Today's games, sports statistics and the full value board",
                'Player IQ ratings across every tracked league',
                'AI predictions, five a day',
                'Player prop analysis, three a day',
                'Parlay builder, ten graded a day',
                'Your own picks tracker and results dashboard',
                'No card, no automatic charge, no renewal',
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
                /*
                 * Available during the trial, per the feature table,
                 * though it is otherwise an Elite grant.
                 *
                 * The cost is worth stating plainly: Elite sells two
                 * things above Pro, and this is one of them. A trialist
                 * therefore sees everything Elite has except the
                 * projected props, so what Elite is selling them at the
                 * end of three days is that one feature and the absence
                 * of a clock.
                 */
                'playeriq',
            ],
            /*
             * Daily allowances are counted on the UTC calendar day and
             * reset at midnight UTC, which is what makes "five a day"
             * the same five for everybody rather than a number that
             * depends where somebody is standing.
             */
            'limits' => [
                'reads_per_day' => 5,
                'prop_analyses_per_day' => 3,
                'tracked_bets' => 25,
                'parlays_per_day' => 10,
            ],
        ],

        /*
         * The trial is over and nothing was bought.
         *
         * `bets.track` and nothing else. The spec asks that the account
         * stay alive and its history be preserved, and preserved data
         * nobody can open is not preserved in any sense that matters to
         * the person who entered it. What they lose is the product;
         * what they keep is their own record of it.
         *
         * Saved picks and saved parlays are reachable too - those routes
         * are ungated by design, for the same reason.
         */
        'expired' => [
            'name' => 'Trial ended',
            'blurb' => 'Your account and history are intact.',
            'monthly_cents' => 0,
            'yearly_cents' => 0,
            'features' => [],
            'grants' => [
                'bets.track',
            ],
            'limits' => ['reads_per_day' => 0, 'prop_analyses_per_day' => 0, 'tracked_bets' => 25, 'parlays_per_day' => 0],
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
            'limits' => ['reads_per_day' => null, 'prop_analyses_per_day' => null, 'tracked_bets' => null, 'parlays_per_day' => null],
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
            'limits' => ['reads_per_day' => null, 'prop_analyses_per_day' => null, 'tracked_bets' => null, 'parlays_per_day' => null],
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
            'limits' => ['reads_per_day' => null, 'prop_analyses_per_day' => null, 'tracked_bets' => null, 'parlays_per_day' => null],
        ],

    ],
];
