<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The engine
|--------------------------------------------------------------------------
|
| The product reads its numbers from the engine and from nowhere else. It
| never recomputes an edge, a probability or a verdict: two places that
| both know how to work one out is how a screen ends up disagreeing with
| the table behind it.
|
| This file used to hold a base URL, a bearer token, HTTP timeouts, a
| retry policy and a circuit breaker, because the engine was a second
| deployment and every board row crossed a network to get here. The two
| halves now share one database and one process. What is left is the two
| settings that were never about transport: how much to read at a time,
| and when to stop calling the data current.
|
*/

return [

    /*
     * Whether to read the engine at all.
     *
     * On by default, because the engine is now in the same process and
     * there is no credential to be missing. It stays configurable so a
     * deployment whose engine has not been given its data yet can say
     * "not connected" on the screens rather than render a confident,
     * empty board - which is the one thing worse than saying nothing.
     */
    'enabled' => (bool) env('ENGINE_ENABLED', true),

    /*
     * Rows per page when rebuilding a mirror.
     *
     * Still paged, although nothing crosses a network. A first run
     * against a long history should take several ticks rather than one
     * job that holds a whole table in memory until a worker kills it.
     */
    'page_size' => (int) env('ENGINE_PAGE_SIZE', 200),

    /*
    |--------------------------------------------------------------------------
    | When data counts as delayed
    |--------------------------------------------------------------------------
    |
    | Never stale data presented as live. Past this age the UI says "data
    | delayed" and how old the last good run is, rather than rendering a
    | board that looks current and is not.
    |
    | Per feed, because they move at different speeds: the board is
    | rescanned hourly, verdicts change when a model is retrained, and a
    | schedule barely moves at all.
    |
    */
    'stale_after_minutes' => [
        'board' => (int) env('ENGINE_STALE_BOARD', 90),
        'events' => (int) env('ENGINE_STALE_EVENTS', 24 * 60),
        'models' => (int) env('ENGINE_STALE_MODELS', 7 * 24 * 60),
        'odds' => (int) env('ENGINE_STALE_ODDS', 20),
    ],

];
