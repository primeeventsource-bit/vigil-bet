<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Sport definitions
|--------------------------------------------------------------------------
|
| Adding a sport is a config entry plus a data-source adapter. Nothing in
| app/ should hardcode a sport key; read everything from here.
|
| score_model values are hints for Phase 2 predictors:
|   margin_normal - normal distribution over margin (NFL/NBA/NCAA)
|   poisson       - low-scoring independent events (NHL)
|   neg_binomial  - overdispersed counts (MLB)
|
| `markets` are the three core markets the model is trained and judged on.
| `prop_markets` are player props, which are pulled for display only and
| are absent from a sport until props for it are worth the credits. Nothing
| reads prop_markets to make a judgement; see config/edge.php.
|
*/

return [

    'nfl' => [
        'label' => 'NFL',
        'group' => 'football',
        'odds_api_key' => 'americanfootball_nfl',
        'espn_path' => 'football/nfl',
        'markets' => ['h2h', 'spreads', 'totals'],
        // Player props, pulled per game for display and line shopping.
        // Not modelled: see `prop_markets` in the Sport enum.
        'prop_markets' => ['player_pass_yds', 'player_rush_yds', 'player_reception_yds', 'player_anytime_td'],
        'has_draw' => false,
        'score_model' => 'margin_normal',
        'elo_per_point' => 25.0,
        'margin_sigma' => 13.5,
        'baseline_total' => 44.0,
        'home_advantage_points' => 2.0,
        'outdoor' => true,
        'season_months' => [9, 10, 11, 12, 1, 2],
        'sources' => [
            'schedules' => 'nflverse',
            'results' => 'nflverse',
            // Box scores only: nflverse games.csv has scores and rest days
            // and nothing from inside the game. ESPN event ids are linked
            // onto these events by ingest:link-espn so this can join.
            'team_stats' => 'espn',
            'injuries' => 'espn',
            'weather' => 'open_meteo',
        ],
    ],

    'nba' => [
        'label' => 'NBA',
        'group' => 'basketball',
        'odds_api_key' => 'basketball_nba',
        'espn_path' => 'basketball/nba',
        'markets' => ['h2h', 'spreads', 'totals'],
        'prop_markets' => ['player_points', 'player_rebounds', 'player_assists', 'player_threes'],
        'has_draw' => false,
        'score_model' => 'margin_normal',
        'elo_per_point' => 28.0,
        'margin_sigma' => 11.5,
        'baseline_total' => 228.0,
        'home_advantage_points' => 2.5,
        'outdoor' => false,
        'season_months' => [10, 11, 12, 1, 2, 3, 4, 5, 6],
        'sources' => [
            'schedules' => 'espn',
            'results' => 'espn',
            'team_stats' => 'espn',
            'injuries' => 'balldontlie',
        ],
    ],

    'mlb' => [
        'label' => 'MLB',
        'group' => 'baseball',
        'odds_api_key' => 'baseball_mlb',
        'espn_path' => 'baseball/mlb',
        'markets' => ['h2h', 'spreads', 'totals'],
        'has_draw' => false,
        'score_model' => 'neg_binomial',
        'elo_per_point' => 100.0,
        'baseline_total' => 8.6,
        'dispersion' => 4.0,
        'home_advantage_points' => 0.2,
        'outdoor' => true,
        'season_months' => [3, 4, 5, 6, 7, 8, 9, 10],
        'sources' => [
            'schedules' => 'mlb_statsapi',
            'results' => 'mlb_statsapi',
            // Box scores only: the league API gives schedules and
            // results, not what happened inside the game. ESPN event ids
            // are linked onto these events by ingest:link-espn.
            'team_stats' => 'espn',
            'starters' => 'mlb_statsapi',
            'injuries' => 'espn',
            'weather' => 'open_meteo',
        ],
    ],

    'nhl' => [
        'label' => 'NHL',
        'group' => 'hockey',
        'odds_api_key' => 'icehockey_nhl',
        'espn_path' => 'hockey/nhl',
        'markets' => ['h2h', 'spreads', 'totals'],
        'has_draw' => false,
        'score_model' => 'poisson',
        'elo_per_point' => 110.0,
        'baseline_total' => 6.2,
        'home_advantage_points' => 0.2,
        'outdoor' => false,
        'season_months' => [10, 11, 12, 1, 2, 3, 4, 5, 6],
        'sources' => [
            'schedules' => 'nhl',
            'results' => 'nhl',
            // Box scores only: the league API gives schedules and
            // results, not what happened inside the game. ESPN event ids
            // are linked onto these events by ingest:link-espn.
            'team_stats' => 'espn',
            'starters' => 'nhl',
            'injuries' => 'espn',
        ],
    ],

    'ncaaf' => [
        'label' => 'NCAAF',
        'group' => 'football',
        'odds_api_key' => 'americanfootball_ncaaf',
        'espn_path' => 'football/college-football',
        'markets' => ['h2h', 'spreads', 'totals'],
        'has_draw' => false,
        'score_model' => 'margin_normal',
        'elo_per_point' => 24.0,
        'margin_sigma' => 16.5,
        'baseline_total' => 52.0,
        'home_advantage_points' => 2.5,
        'outdoor' => true,
        'season_months' => [8, 9, 10, 11, 12, 1],
        'sources' => [
            'schedules' => 'cfbd',
            'results' => 'cfbd',
            'team_stats' => 'cfbd',
            'injuries' => 'espn',
            'weather' => 'open_meteo',
        ],
    ],

    'ncaab' => [
        'label' => 'NCAAB',
        'group' => 'basketball',
        'odds_api_key' => 'basketball_ncaab',
        'espn_path' => 'basketball/mens-college-basketball',
        'espn_groups' => 50,
        'markets' => ['h2h', 'spreads', 'totals'],
        'has_draw' => false,
        'score_model' => 'margin_normal',
        'elo_per_point' => 27.0,
        'margin_sigma' => 10.5,
        'baseline_total' => 145.0,
        'home_advantage_points' => 3.0,
        'outdoor' => false,
        'season_months' => [11, 12, 1, 2, 3, 4],
        'sources' => [
            'schedules' => 'espn',
            'results' => 'espn',
            'team_stats' => 'espn',
            'injuries' => 'espn',
        ],
    ],

];
