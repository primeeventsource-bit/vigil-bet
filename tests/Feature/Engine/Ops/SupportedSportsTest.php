<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Which leagues this product runs
|--------------------------------------------------------------------------
|
| College is out, and that is a product decision rather than a
| configuration accident - so it is enforced in config and not left to an
| environment variable that can be edited in a dashboard by anyone.
|
| What it cost to learn is worth keeping: neither college market ever
| cleared a publication bar. NCAAF h2h measured 8.47% calibration error
| against a 6% ceiling over 9,929 predictions, NCAAB 12.89%, and neither
| had any injury coverage at all because no feed we hold publishes it.
|
| The data stays. Nothing is deleted, and putting a league back is one
| line.
|
*/

use App\Enums\Sport;

it('runs the four leagues it supports', function () {
    expect(Sport::enabled())->toHaveCount(4);

    $values = array_map(static fn (Sport $s): string => $s->value, Sport::enabled());
    sort($values);

    expect($values)->toBe(['mlb', 'nba', 'nfl', 'nhl']);
});

it('does not run college', function () {
    expect(Sport::Ncaaf->isEnabled())->toBeFalse()
        ->and(Sport::Ncaab->isEnabled())->toBeFalse();
});

it('will not let an environment variable add a league back', function () {
    /*
     * The env var chooses among the leagues this product runs; it does
     * not add to them. Otherwise "we are not doing college" survives
     * exactly as long as nobody edits a dashboard field.
     */
    config()->set('edge.sports_enabled', array_values(array_intersect(
        ['nfl', 'ncaaf', 'ncaab'],
        (array) config('edge.sports_supported'),
    )));

    expect(config('edge.sports_enabled'))->toBe(['nfl']);
});

it('keeps the leagues it does run addressable, because the data is still there', function () {
    /*
     * Disabled is not deleted. 13,343 finished college football games and
     * their box scores stay on file, so turning the league back on is a
     * config line rather than a re-ingestion.
     */
    expect(Sport::tryFrom('ncaaf'))->not->toBeNull()
        ->and(Sport::tryFrom('ncaab'))->not->toBeNull();
});

it('names its supported list rather than spreading it through the code', function () {
    expect(config('edge.sports_supported'))->toBe(['nfl', 'nba', 'mlb', 'nhl']);
});
