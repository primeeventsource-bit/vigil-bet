<?php

declare(strict_types=1);

return [

    /*
    |----------------------------------------------------------------------
    | Which leagues this install covers
    |----------------------------------------------------------------------
    |
    | Everything scheduled fans out over this list, so a league removed
    | here stops being pulled, priced and scanned without anything else
    | needing to change - and stops spending the odds quota.
    |
    | College is deliberately not on it. That is a product decision rather
    | than a configuration accident, so it is enforced here and an
    | EDGE_SPORTS naming ncaaf or ncaab will not bring it back: the env var
    | chooses among the leagues this product runs, it does not add to them.
    |
    | What it cost to find out is worth writing down. Neither college
    | market ever cleared a publication bar - ncaaf h2h sat at 8.47%
    | calibration error against a 6% ceiling over 9,929 measured
    | predictions, and ncaab at 12.89% - and neither had any injury
    | coverage at all, because no feed we have publishes it. The data is
    | kept; nothing is deleted. Putting either back is one line here.
    */
    'sports_supported' => ['nfl', 'nba', 'mlb', 'nhl'],

    'sports_enabled' => array_values(array_intersect(
        array_values(array_filter(
            array_map('trim', explode(',', (string) env('EDGE_SPORTS', 'nfl,nba,mlb,nhl')))
        )),
        ['nfl', 'nba', 'mlb', 'nhl'],
    )),

    /*
    |----------------------------------------------------------------------
    | The Odds API
    |----------------------------------------------------------------------
    */
    'odds_api' => [
        'key' => env('ODDS_API_KEY'),
        'base_url' => env('ODDS_API_BASE', 'https://api.the-odds-api.com/v4'),
        'regions' => array_map('trim', explode(',', (string) env('ODDS_API_REGIONS', 'us,us2'))),
        'odds_format' => 'american',
        'timeout' => 15,
        'retries' => 3,
        'retry_sleep_ms' => 500,
        // Hard stop so a runaway scheduler cannot burn the monthly quota.
        //
        // This is credits, not calls. A call to the odds endpoint costs a
        // credit per market per region; a call for player props costs a
        // credit per market per region returned; a call to the historical
        // endpoint costs ten. Counting calls and calling it quota was close
        // enough while every call cost one, and stops being true the moment
        // props or history are pulled.
        'monthly_quota' => (int) env('ODDS_API_MONTHLY_QUOTA', 25000000),
        'quota_reserve_pct' => 10,

        /*
        |------------------------------------------------------------------
        | Player props
        |------------------------------------------------------------------
        |
        | Props come from the per-event endpoint, so they cost a call per
        | game rather than a call per league. Which markets, per sport, is
        | in config/sports.php under `prop_markets`.
        |
        | They are display and line shopping only. No model has been
        | measured on a prop market, so no prop row can become a pick: they
        | are stored with a `market_key` and a null `market`, and every
        | reader that feeds the model asks for the three core markets by
        | name.
        |
        */
        'props' => [
            'enabled' => (bool) env('ODDS_API_PROPS_ENABLED', true),
            // Books do not post props days out, and asking before they do
            // spends a call on an empty answer.
            'horizon_hours' => (int) env('ODDS_API_PROPS_HORIZON_HOURS', 36),
            // A ceiling on one run, so a full Saturday card cannot turn a
            // single tick into several hundred calls.
            'max_events_per_run' => (int) env('ODDS_API_PROPS_MAX_EVENTS', 40),
            // Same shape as the cadence below, read the same way. Looser,
            // because a prop line moves on news rather than continuously
            // and every game is its own call.
            'cadence' => [
                ['within_minutes' => 60, 'every_minutes' => 10],
                ['within_minutes' => 12 * 60, 'every_minutes' => 60],
                ['within_minutes' => 36 * 60, 'every_minutes' => 240],
            ],
        ],

        /*
        |------------------------------------------------------------------
        | Historical closing lines
        |------------------------------------------------------------------
        |
        | The historical endpoint costs ten credits per market per region,
        | which is where a monthly quota goes to die if nobody is counting.
        | So a backfill states its estimated cost up front and refuses to
        | start when that estimate is more than this share of what is left.
        |
        | The share is of *remaining* credits rather than of the plan,
        | because the only credits that can be spent are the ones still
        | there.
        |
        */
        'historical' => [
            'credits_per_market_region' => 10,
            'max_share_of_remaining_pct' => (float) env('ODDS_API_BACKFILL_MAX_SHARE_PCT', 25.0),
            // How long before kickoff to ask for the snapshot. The endpoint
            // answers with the snapshot at or before the timestamp given,
            // so asking exactly at kickoff can return a price from after
            // the market was taken down. A few minutes early is still the
            // closing line.
            'lead_minutes' => (int) env('ODDS_API_BACKFILL_LEAD_MINUTES', 5),
            // One request returns every game in the sport as at one moment,
            // so kickoffs this close together share a request.
            'bucket_minutes' => (int) env('ODDS_API_BACKFILL_BUCKET_MINUTES', 15),
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Pull cadence (minutes between pulls, by time until kickoff)
    |----------------------------------------------------------------------
    | Evaluated top down; the first window whose `within_minutes` covers the
    | next start time wins. Tighten close to game time for honest closing
    | lines, stay cheap days out.
    */
    'cadence' => [
        ['within_minutes' => 60, 'every_minutes' => 2],
        ['within_minutes' => 12 * 60, 'every_minutes' => 15],
        ['within_minutes' => 7 * 24 * 60, 'every_minutes' => 60],
    ],

    /*
    |----------------------------------------------------------------------
    | My sportsbook accounts
    |----------------------------------------------------------------------
    |
    | Only these books can produce a recommendation, because a price I cannot
    | reach is not an edge. The first entry is the default. Every other
    | tracked book still feeds the no-vig fair price, since a wider sample
    | makes a sharper estimate of the true probability.
    |
    | Hard Rock Bet is served under the us2 region, so ODDS_API_REGIONS must
    | keep us2 in it.
    |
    */
    'my_books' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('EDGE_MY_BOOKS', 'hardrockbet,draftkings')))
    )),

    /*
    |----------------------------------------------------------------------
    | The pricing book
    |----------------------------------------------------------------------
    |
    | Every price, edge, stake and pick is measured at this one book, because
    | this is where the bet actually gets placed. A bet that is only good
    | somewhere else is a pass, labelled "price too short here" rather than
    | quietly dropped.
    |
    | Other books are still pulled and still matter: they are what the no-vig
    | fair probability is built from, and a wider sample is a sharper estimate
    | of the true price. They are never recommended.
    |
    | Switch this to draftkings to price everything there instead.
    |
    */
    'pricing_book' => (string) env('EDGE_PRICING_BOOK', 'hardrockbet'),

    /*
    |----------------------------------------------------------------------
    | Value finder defaults (Phase 3). Overridable in bankroll_settings.
    |----------------------------------------------------------------------
    */
    'value' => [
        'edge_threshold' => 0.03,

        /*
         * How far a market that has not beaten the closing line may be
         * published as disagreeing with it.
         *
         * Measured on 25 October 2026, with the calibrated tier live: the
         * model put Philadelphia at 62.3% where the no-vig market had
         * 33.6%, and the board offered that as a 27.8% edge. Professional
         * edges run one to three points. A model that loses to the close
         * - which every market here does - claiming the book is twenty-
         * eight points wrong is the model being wrong, published as a
         * recommendation.
         *
         * So the deviation from the market is shrunk toward it before any
         * edge is taken: published = market + k x (model - market). At
         * 0.25 the Philadelphia number becomes 40.8% and a 7.2% edge,
         * which is a claim the evidence can carry.
         *
         * This is a policy ceiling, not a measured coefficient, and it is
         * named as one. A market that does beat the close has earned the
         * right to disagree and is not shrunk at all - see
         * Verdict::claimsEdge().
         */
        'unproven_edge_shrink' => 0.25,

        /*
         * How much a lopsided injury list tightens that shrink.
         *
         * The engine's probability is a power rating and has never seen
         * an injury report. A book reprices within minutes of a
         * quarterback being ruled out, so an absence is information the
         * market holds and this model does not - and the response is to
         * disagree with the market less on those games rather than to
         * invent a points-per-injury number nobody measured.
         *
         * It is the imbalance that counts, not the count. Measured on 25
         * October 2026, every upcoming NFL game had two to six players
         * out per side; that is ordinary attrition and roughly cancels in
         * a head-to-head price. Indianapolis at Minnesota was five
         * against none, which does not cancel at all.
         *
         * At 0.25 an imbalance of one divides the shrink by 1.25 and an
         * imbalance of five divides it by 2.25, so a one-sided list moves
         * the published number most of the way back to the book.
         */
        'absence_imbalance_weight' => 0.25,
        // A pick must also win often enough to be worth showing. This is a
        // display preference, not a profitability test: a 52% bet at a good
        // price is profitable, it just is not what was asked for.
        'min_win_probability' => (float) env('EDGE_MIN_WIN_PROBABILITY', 0.55),
        'min_book_count' => 3,
        'vig_method' => 'multiplicative',
        'require_proven_market' => true,
        // A quote older than this is stale: no recommendation, ever.
        'max_quote_age_minutes' => 20,
        'max_picks_shown' => 10,
    ],

    /*
    |----------------------------------------------------------------------
    | Parlays
    |----------------------------------------------------------------------
    |
    | A parlay compounds the book's margin and the model's error at the same
    | rate it compounds the payout, which is why the defaults here are tight.
    |
    | The shade is the amount every leg's probability is knocked down by
    | before the parlay is judged. A model that is routinely two points
    | optimistic about itself is an ordinary model, not a broken one, and an
    | edge that does not survive two points per leg was never an edge. It is
    | applied in addition to the single-bet edge threshold, not instead.
    |
    */
    'parlay' => [
        'shade' => (float) env('EDGE_PARLAY_SHADE', 0.02),
        'min_legs' => 2,
        'max_legs' => (int) env('EDGE_PARLAY_MAX_LEGS', 5),
    ],

    /*
    |----------------------------------------------------------------------
    | Cross-book tools: arbitrage and middles
    |----------------------------------------------------------------------
    |
    | Both of these find disagreements between books rather than errors in
    | a price, so neither consults the model to decide anything and neither
    | produces a pick. They are reported, never recommended.
    |
    | The profit floor exists because an arbitrage of a few basis points is
    | indistinguishable from one book being a few seconds behind another,
    | and chasing it is how you get a bet rejected on one leg and an open
    | position on the other. Raise it rather than lower it.
    |
    | The reference stake is only the number the split and the profit are
    | quoted against. It is not a recommendation and is deliberately not
    | the Kelly stake: an arbitrage has no edge to size against, and a
    | middle is sized by what you are willing to lose on a miss.
    |
    */
    'crossbook' => [
        'reference_stake_cents' => (int) env('EDGE_CROSSBOOK_STAKE_CENTS', 100000),
        'min_arb_profit' => (float) env('EDGE_MIN_ARB_PROFIT', 0.005),
        'horizon_hours' => (int) env('EDGE_CROSSBOOK_HORIZON_HOURS', 72),
    ],

    /*
    |----------------------------------------------------------------------
    | Bankroll defaults (Phase 4). Cents everywhere.
    |----------------------------------------------------------------------
    */
    'bankroll' => [
        'starting_cents' => (int) env('EDGE_STARTING_BANKROLL_CENTS', 500000),
        'unit_size_cents' => (int) env('EDGE_UNIT_SIZE_CENTS', 10000),

        // Absolute per-bet bounds, alongside the percentage cap below.
        // The floor is what you are actually willing to walk up and place;
        // when correct sizing lands under it, the bet is skipped rather than
        // rounded up. The ceiling is a hard stop on any single bet.
        'min_stake_cents' => (int) env('EDGE_MIN_STAKE_CENTS', 10000),
        'max_stake_cents' => (int) env('EDGE_MAX_STAKE_CENTS', 100000),

        'kelly_fraction' => (float) env('EDGE_KELLY_FRACTION', 0.25),
        'max_stake_pct' => (float) env('EDGE_MAX_STAKE_PCT', 2.0),
        'max_daily_exposure_pct' => (float) env('EDGE_MAX_DAILY_EXPOSURE_PCT', 6.0),
        'daily_loss_limit_cents' => (int) env('EDGE_DAILY_LOSS_LIMIT_CENTS', 50000),
        'weekly_loss_limit_cents' => (int) env('EDGE_WEEKLY_LOSS_LIMIT_CENTS', 100000),
    ],

    /*
    |----------------------------------------------------------------------
    | Backtest verdict gates. A market is only "proven" when the model beats
    | the no-vig closing line on log loss by more than chance explains, AND
    | is no worse calibrated than that same line. Anything less is reported
    | as unproven and produces no picks.
    |
    | Closing line value is deliberately absent from this list. It cannot be
    | computed from historical closing lines - it needs the price a bet was
    | struck at - and the number that used to sit here was a home-side bias
    | measure that a constant shade could satisfy. Real CLV is tracked on
    | placed bets instead. See Verdicter for the full account.
    |----------------------------------------------------------------------
    */
    'verdict' => [
        'min_predictions' => 300,
        'min_log_loss_improvement' => 0.0,
        // Standard errors above zero the log loss improvement must clear.
        // 1.64 is a one-sided 95 percent bar. Without it, noise proves models.
        'min_t_stat' => 1.64,
        // Probability points of calibration the model may give up to the
        // market before it is disqualified. Stake sizing reads the
        // probability literally, so a sharper but miscalibrated model is
        // more dangerous than a blunt one.
        'calibration_tolerance' => 0.01,
    ],

    /*
     * Keys are read under more than one name on purpose.
     *
     * A key that is set and not read is the worst failure mode available
     * here: the screen says "missing key", the operator has plainly just
     * added one, and nothing says the two are different strings. It has
     * already happened once - cfbd_key went into the environment and
     * CFBD_API_KEY was what the code asked for, and on Linux those are two
     * different variables. Accepting both costs a fallback and removes the
     * whole class.
     */
    'sources' => [
        'cfbd' => [
            'key' => env('CFBD_API_KEY', env('cfbd_key')),
            'base_url' => 'https://api.collegefootballdata.com',
        ],
        // Same provider, same bearer token, different host: one CFBD
        // account covers the basketball API too, so the football key is
        // the fallback rather than a second thing to go and get.
        'cbd' => [
            'key' => env('CBD_API_KEY', env('cbd_key', env('CFBD_API_KEY', env('cfbd_key')))),
            'base_url' => 'https://api.collegebasketballdata.com',
        ],
        'nflverse' => [
            'base_url' => 'https://github.com/nflverse/nflverse-data/releases/download',
        ],
        'mlb_statsapi' => ['base_url' => 'https://statsapi.mlb.com/api/v1'],
        'nhl' => ['base_url' => 'https://api-web.nhle.com/v1'],
        'nba' => [
            'base_url' => 'https://cdn.nba.com/static/json',
            'stats_base_url' => 'https://stats.nba.com/stats',
        ],
        // Paid, NBA only on this account. Availability reports from a
        // documented keyed API rather than an undocumented scrape, which
        // is the whole reason it is worth money.
        'balldontlie' => [
            'key' => env('BALLDONTLIE_API_KEY', env('balldontlie_key')),
            'base_url' => 'https://api.balldontlie.io/v1',
        ],
        'open_meteo' => ['base_url' => 'https://api.open-meteo.com/v1'],
    ],

    /*
     * Memory ceiling for work that walks thousands of rows.
     *
     * Laravel Cloud's managed ini sets `memory_limit` to 256M inside a
     * container the cgroup allows 1024M, so three quarters of the
     * allowance was unreachable by any single process:
     * `ingest:box-scores mlb --limit=1200` died on PHP's limit while the
     * container had 768M spare, and every backfill since has run in
     * chunks of 250 to stay under it.
     *
     * Applied by App\Support\BatchMemory at the start of the commands and
     * jobs that need it, and nowhere else. A web request wanting half a
     * gigabyte is a bug and should fail at 256M rather than quietly
     * succeed at 512M.
     *
     * 512M leaves the container half its allowance for everything else,
     * including the queue worker running something else at the time.
     */
    'batch_memory_limit' => env('EDGE_BATCH_MEMORY_LIMIT', '512M'),

    /*
    |----------------------------------------------------------------------
    | Player ratings
    |----------------------------------------------------------------------
    */
    'players' => [
        /*
         * How many games of evidence it takes to be trusted as far as
         * the crowd.
         *
         * A player's measures are pulled toward their peer group's
         * average in proportion to how little we have seen of him:
         * (games x observed + k x mean) / (games + k). At 20, a player
         * with twenty games is trusted half as far as the group, one
         * with two hundred almost entirely, and one with ten barely at
         * all.
         *
         * Without it a percentile over fifteen innings and one over a
         * hundred and thirty read as the same claim, and a reliever with
         * ten appearances outranked Mason Miller at a hundred and
         * thirty - a good fortnight beats a good season on rate alone.
         *
         * Zero turns it off.
         */
        'shrink_games' => 20,
    ],

    /*
     * Player prop projections.
     *
     * Note what is NOT here: a shrink for the projected level. The
     * ratings shrink toward the peer mean because they produce a rank,
     * and shrinkage protects a rank. A projection produces a level that
     * is compared against a line, and shrinking it would bias every
     * comparison the same direction - a back averaging a hundred rushing
     * yards pulled toward a pool averaging twenty-five projects
     * seventy-eight, and the model says "under" on everything while each
     * individual number still looks sober. Only the spread is pooled,
     * and only as a scale-free ratio.
     */
    'props' => [
        /*
         * Games before a player can be projected at all.
         *
         * Matches the ratings, and for the same reason: fewer than ten
         * is a sample that can produce any number asked of it. Below
         * this the projection is refused rather than widened, because a
         * refusal is legible and a very wide distribution reads as an
         * opinion.
         */
        'min_games' => 10,

        /*
         * Half-life, in games, of how much an old game counts.
         *
         * A sample has to span seasons to say anything in October, and
         * the user asked for exactly that - last season's stats
         * informing this season's. But a player's role in October is not
         * his role last March, so the older games inform rather than
         * govern: at 20, a game twenty back weighs half as much as
         * tonight's and one forty back a quarter.
         *
         * Zero weights every game equally.
         */
        'recency_half_life_games' => 20,

        /*
         * How far a player's own spread is trusted against his group's.
         *
         * A variance over twelve games is poorly estimated in a way a
         * mean is not, and pooling it costs no bias in the level - the
         * dispersion index and the coefficient of variation are both
         * scale-free, so the group's value is multiplied back by the
         * player's own mean rather than replacing it.
         */
        'dispersion_pool_games' => 8,

        /*
         * The prior behind a "did it happen at all" rate.
         *
         * Anytime touchdown, and the one place a level IS shrunk. A rate
         * is bounded at zero, so a player who has not scored in eleven
         * games has an unshrunk estimate of exactly nil - a claim he
         * cannot score, which eleven games cannot support. The pull
         * toward the group rate is what stops an absence being read as a
         * certainty.
         */
        'threshold_prior_games' => 8,

        /*
         * How many of his team's games a player may have missed and
         * still be projected.
         *
         * The other half of the role problem. A projection averages a
         * player's history and the market prices the player he is
         * tonight: when the role grew the model takes the under, and
         * when it collapsed the model takes the over - louder, because
         * the history is long and the collapse is recent.
         *
         * Adam Trautman went 12.8, 14.5 and 17.7 receiving yards a game
         * over three seasons, has played twice this season for five, and
         * last appeared three team games ago. The board priced over half
         * a yard at even money and the projection called it a 34-point
         * edge on the over.
         *
         * Two allows for a bye plus one missed week. Above it the
         * projection is refused rather than discounted, because how far
         * to discount a player who may be injured, benched or cut is not
         * something this knows, and a number invented for it would be
         * the least examined part of the estimate.
         *
         * Zero turns the check off.
         */
        'max_team_games_missed' => 2,
    ],

    'http' => [
        /*
         * Read timeout, in seconds.
         *
         * Twenty was not enough, and the failure was quiet:
         * `ingest:results:ncaaf` failed with "cURL error 28" seven times
         * in twelve hours while every other sport succeeded. Nothing
         * looked broken anywhere a dashboard looks - the games simply
         * stayed marked scheduled hours after they had finished, and
         * anything waiting on a final score waited for ever.
         *
         * Not because the endpoint is slow. Measured directly it answers
         * in under five seconds, and that measurement is the point: the
         * timeouts clustered in the window when the odds backfill was
         * saturating the single worker, so twenty seconds of wall clock
         * was being spent somewhere other than waiting for CFBD. A
         * timeout has to have headroom over the worst the machine is
         * doing, not over the best the source does.
         *
         * Forty-five gives that headroom and is still a timeout: a
         * source that has genuinely stopped answering fails inside the
         * minute rather than holding a worker.
         */
        'timeout' => 45,
        'connect_timeout' => 8,
        'retries' => 3,
        'retry_sleep_ms' => 400,
        'user_agent' => 'EdgeLab/1.0 (private analytics; single user)',
    ],
];
