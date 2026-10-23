<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Edge Lab
|--------------------------------------------------------------------------
|
| Vigil reads its numbers from Edge Lab's private internal API and from
| nowhere else. It never recomputes an edge, a probability or a verdict:
| two places that both know how to work one out is how a screen ends up
| disagreeing with the table behind it.
|
| The contract is edge-lab/docs/INTERNAL_API.md.
|
*/

return [

    /*
     * Off unless a token is set. Every sync job records a skip and spends
     * nothing, and the sync-health page says plainly that it is off rather
     * than showing an empty board as though it were a quiet night.
     */
    'enabled' => filled(env('EDGE_LAB_TOKEN')),

    'base_url' => rtrim((string) env('EDGE_LAB_URL', 'https://parlayq.com/internal/v1'), '/'),
    'token' => env('EDGE_LAB_TOKEN'),

    'http' => [
        'timeout' => (int) env('EDGE_LAB_TIMEOUT', 15),
        'connect_timeout' => (int) env('EDGE_LAB_CONNECT_TIMEOUT', 5),
        /*
         * Retries are for a connection that blinked, not for an answer we
         * did not like. A 4xx is a disagreement about the request and will
         * say the same thing however many times it is asked.
         */
        'retries' => (int) env('EDGE_LAB_RETRIES', 3),
        'retry_base_ms' => (int) env('EDGE_LAB_RETRY_BASE_MS', 250),
        'user_agent' => 'Vigil/1.0 (+sync)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | Edge Lab rate limits per minute, and a sync job that keeps hammering a
    | dead or angry upstream turns one outage into two. After this many
    | consecutive failures the breaker opens and every call fails fast
    | without leaving the building, until the cooldown lets one through to
    | find out whether it is back.
    |
    */
    'breaker' => [
        'failure_threshold' => (int) env('EDGE_LAB_BREAKER_FAILURES', 5),
        'cooldown_seconds' => (int) env('EDGE_LAB_BREAKER_COOLDOWN', 120),
    ],

    'page_size' => (int) env('EDGE_LAB_PAGE_SIZE', 200),

    /*
    |--------------------------------------------------------------------------
    | When data counts as delayed
    |--------------------------------------------------------------------------
    |
    | Never stale data presented as live. Past this age the UI says "data
    | delayed" and how old the last good sync is, rather than rendering a
    | board that looks current and is not.
    |
    | Per feed, because they move at different speeds: the board is rescanned
    | hourly, models change when one is retrained, and a schedule barely
    | moves at all.
    |
    */
    'stale_after_minutes' => [
        'board' => (int) env('EDGE_LAB_STALE_BOARD', 90),
        'events' => (int) env('EDGE_LAB_STALE_EVENTS', 24 * 60),
        'models' => (int) env('EDGE_LAB_STALE_MODELS', 7 * 24 * 60),
        'odds' => (int) env('EDGE_LAB_STALE_ODDS', 20),
    ],

];
