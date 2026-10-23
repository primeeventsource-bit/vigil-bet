<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| §12: a load test on the board.
|--------------------------------------------------------------------------
|
| Not a benchmark. A benchmark on a laptop against SQLite tells you almost
| nothing about Postgres under real traffic, and a wall-clock assertion
| would fail on a busy CI box for reasons that have nothing to do with the
| code.
|
| What is worth asserting is the thing that actually breaks a dashboard:
| query count that grows with the data. A page that runs four queries on
| an empty board and four hundred on a full one works in development and
| falls over on the first real Sunday - and that is a property a test can
| hold, deterministically, on any machine.
|
*/

it('runs no more queries on a full board than on an empty one', function () {
    $user = betUser('pro');

    $empty = queriesFor(fn () => $this->actingAs($user)->get(route('today'))->assertOk());

    fullBoard();

    $full = queriesFor(fn () => $this->actingAs($user)->get(route('today'))->assertOk());

    /*
     * Some growth is legitimate - the Reads are fetched in one extra
     * query once there are picks to fetch them for - but it must be a
     * constant, not a multiple of the board size.
     *
     * Sixteen games, six books and three quotes each is 288 odds rows
     * and 16 picks. A page that lazy-loaded per pick would land in the
     * dozens here, and one that lazy-loaded per quote in the hundreds.
     */
    expect($full)->toBeLessThanOrEqual($empty + 4);
})->group('load');

it('keeps the query count flat as the board doubles', function () {
    $user = betUser('pro');

    fullBoard(games: 8);
    $small = queriesFor(fn () => $this->actingAs($user)->get(route('today'))->assertOk());

    fullBoard(games: 16);
    $large = queriesFor(fn () => $this->actingAs($user)->get(route('today'))->assertOk());

    /*
     * Doubling the data must not increase the number of round trips.
     * Asserted as "no more" rather than "exactly equal": the count can
     * legitimately fall, because a board with more picks fills the
     * ten-row limit and stops the near-miss query from running at all.
     * Growth is the failure; a decrease is not.
     */
    expect($large)->toBeLessThanOrEqual($small);
})->group('load');

it('holds the query count on every page a full board touches', function (string $route) {
    $user = betUser('pro');

    $empty = queriesFor(fn () => $this->actingAs($user)->get(route($route))->assertOk());

    fullBoard();

    $full = queriesFor(fn () => $this->actingAs($user)->get(route($route))->assertOk());

    expect($full)->toBeLessThanOrEqual($empty + 4);
})->with(['today', 'props', 'tools', 'models', 'bets'])->group('load');

it('renders a full board without touching the odds table per row', function () {
    $user = betUser('pro');

    fullBoard();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)->get(route('today'))->assertOk();

    $odds = collect(DB::getQueryLog())
        ->filter(fn (array $query) => str_contains($query['query'], 'vl_odds_display'))
        ->count();

    DB::disableQueryLog();
    DB::flushQueryLog();

    /*
     * The movement panel reads the odds mirror once. More than a couple
     * of queries against this table means something is asking per pick
     * or per book, and that is the table that grows fastest.
     */
    expect($odds)->toBeLessThanOrEqual(2);
})->group('load');

it('serves a full board in a sane number of queries, in absolute terms', function () {
    $user = betUser('pro');

    fullBoard();

    $queries = queriesFor(fn () => $this->actingAs($user)->get(route('today'))->assertOk());

    /*
     * A ceiling rather than a target, and loose on purpose: the exact
     * count will drift as panels are added. What it catches is a change
     * that takes the dashboard from tens of queries to hundreds, which
     * is the kind of regression nobody notices locally.
     */
    expect($queries)->toBeLessThan(40);
})->group('load');
