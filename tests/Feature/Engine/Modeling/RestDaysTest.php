<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Team;
use Illuminate\Database\UniqueConstraintViolationException;

/*
|--------------------------------------------------------------------------
| Rest days, derived from the schedule.
|--------------------------------------------------------------------------
|
| Rest arrived from the ingestion feed and only the NFL feed supplied it,
| so every other sport had null for every game. Three features depended
| on it and all three were constant zero - which reads in a backtest as
| "no signal" and actually meant "no data".
|
| These tests exist because a wrong rest figure is worse than none: it
| would be a confident number fed to a model, and the back-to-back is the
| most worked angle in basketball.
|
*/

function restTeam(string $slug): Team
{
    return Team::create([
        'sport' => 'nba', 'name' => $slug, 'display_name' => $slug, 'slug' => $slug,
    ]);
}

function restGame(Team $home, Team $away, string $startsAt, array $extra = []): Event
{
    return Event::create(array_merge([
        'sport' => 'nba',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'starts_at' => $startsAt,
        'status' => 'final',
    ], $extra));
}

it('gives the first game of a run no rest figure at all', function () {
    $a = restTeam('a');
    $b = restTeam('b');

    $first = restGame($a, $b, '2026-01-01 19:00:00');

    $this->artisan('edge:schedule:rest', ['sport' => 'nba'])->assertSuccessful();

    // Nothing came before it, so there is nothing to measure. Null, not
    // zero: zero means "played yesterday" and would flag a season opener
    // as a back-to-back.
    expect($first->refresh()->home_rest_days)->toBeNull()
        ->and($first->away_rest_days)->toBeNull();
});

it('counts a back-to-back as one day of rest', function () {
    $a = restTeam('a');
    $b = restTeam('b');
    $c = restTeam('c');

    restGame($a, $b, '2026-01-01 19:00:00');
    $second = restGame($a, $c, '2026-01-02 19:00:00');

    $this->artisan('edge:schedule:rest', ['sport' => 'nba'])->assertSuccessful();

    $second->refresh();

    // The home side played last night; the away side is fresh.
    expect($second->home_rest_days)->toBe(1)
        ->and($second->away_rest_days)->toBeNull();
});

it('measures rest for whichever side a team played on', function () {
    $a = restTeam('a');
    $b = restTeam('b');
    $c = restTeam('c');

    // A plays away, then hosts three days later.
    restGame($b, $a, '2026-01-01 19:00:00');
    $second = restGame($a, $c, '2026-01-04 19:00:00');

    $this->artisan('edge:schedule:rest', ['sport' => 'nba'])->assertSuccessful();

    // Rest is about the team, not about which column it sat in.
    expect($second->refresh()->home_rest_days)->toBe(3);
});

it('treats a season break as no rest figure rather than enormous rest', function () {
    $a = restTeam('a');
    $b = restTeam('b');

    restGame($a, $b, '2026-04-10 19:00:00');
    $opener = restGame($a, $b, '2026-10-20 19:00:00');

    $this->artisan('edge:schedule:rest', ['sport' => 'nba'])->assertSuccessful();

    /*
     * 193 days is true and useless. The model would read it as an
     * enormous advantage for whichever side opened at home, when both
     * sides are equally fresh and equally rusty.
     */
    expect($opener->refresh()->home_rest_days)->toBeNull();
});

it('ignores preseason, so nobody is flagged tired for a game nobody rested for', function () {
    $a = restTeam('a');
    $b = restTeam('b');

    restGame($a, $b, '2026-01-01 19:00:00', ['season_type' => 'pre']);
    $real = restGame($a, $b, '2026-01-02 19:00:00');

    $this->artisan('edge:schedule:rest', ['sport' => 'nba'])->assertSuccessful();

    expect($real->refresh()->home_rest_days)->toBeNull();
});

it('leaves a figure the feed already supplied alone', function () {
    $a = restTeam('a');
    $b = restTeam('b');
    $c = restTeam('c');

    restGame($a, $b, '2026-01-01 19:00:00');
    $second = restGame($a, $c, '2026-01-02 19:00:00', ['home_rest_days' => 7]);

    $this->artisan('edge:schedule:rest', ['sport' => 'nba'])->assertSuccessful();

    // The feed is the better source where it exists. Derivation fills
    // gaps; it does not overrule.
    expect($second->refresh()->home_rest_days)->toBe(7);
});

it('recomputes a feed figure when told to', function () {
    $a = restTeam('a');
    $b = restTeam('b');
    $c = restTeam('c');

    restGame($a, $b, '2026-01-01 19:00:00');
    $second = restGame($a, $c, '2026-01-02 19:00:00', ['home_rest_days' => 7]);

    $this->artisan('edge:schedule:rest', ['sport' => 'nba', '--force' => true])->assertSuccessful();

    expect($second->refresh()->home_rest_days)->toBe(1);
});

it('changes nothing on a dry run', function () {
    $a = restTeam('a');
    $b = restTeam('b');
    $c = restTeam('c');

    restGame($a, $b, '2026-01-01 19:00:00');
    $second = restGame($a, $c, '2026-01-02 19:00:00');

    $this->artisan('edge:schedule:rest', ['sport' => 'nba', '--dry-run' => true])->assertSuccessful();

    expect($second->refresh()->home_rest_days)->toBeNull();
});

it('cannot be handed two fixtures at the same instant to confuse it', function () {
    $a = restTeam('a');
    $b = restTeam('b');

    restGame($a, $b, '2026-01-01 19:00:00');

    /*
     * The negative-rest case this was written to guard against turns out
     * to be unreachable: events carries a unique key on sport, start
     * time and both team ids, so a duplicated schedule row is refused by
     * the database before any derivation sees it.
     *
     * Asserting the constraint rather than the arithmetic, because the
     * constraint is what makes the arithmetic safe.
     */
    expect(fn () => restGame($a, $b, '2026-01-01 19:00:00'))
        ->toThrow(UniqueConstraintViolationException::class);
});
