<?php

declare(strict_types=1);

use App\Support\Integrity\AppendOnlyTables;

/*
|--------------------------------------------------------------------------
| Truncate on append-only tables
|--------------------------------------------------------------------------
|
| Append-only is a row-level BEFORE DELETE trigger, and that does not
| fire on TRUNCATE. The statement-level guard closing that gap is
| Postgres only, and this suite runs on SQLite - which has no TRUNCATE at
| all, and never had the append-only triggers either. So these tests
| cannot execute the guard, and saying otherwise would be worse than not
| testing it.
|
| What they can do is hold the two judgement lists to account, which is
| the part that rots. Coverage itself cannot drift: the migration reads
| the append-only tables out of pg_trigger, so it guards whatever the
| database guards.
|
| The first version of this file could not fail. It asserted that each
| entry in MUST_GUARD satisfied mustGuard(), which is a list checking
| itself - and `event_line_markers` sat on that list, unguarded and not
| append-only at all, with the suite green. Every test here now compares
| a list against something outside it.
|
*/

it('requires a guard only on tables that are actually append-only', function () {
    /*
     * The test that was missing. MUST_GUARD named event_line_markers -
     * where the closing lines live, so it looked like the obvious thing
     * to protect - but that table is a derived cache upserted from
     * odds_snapshots and is not append-only, so the guard never applied
     * to it and never could. Checked against discovery of the trait, not
     * against MUST_GUARD itself.
     */
    $appendOnly = AppendOnlyTables::all();

    expect($appendOnly)->not->toBeEmpty();

    foreach (AppendOnlyTables::MUST_GUARD as $table) {
        expect($appendOnly)->toContain($table);
    }
});

it('leaves truncatable only tables that are actually append-only', function () {
    // A stale entry here is worse than useless: it reads as a considered
    // exception to a guard that has nothing to do with it.
    $appendOnly = AppendOnlyTables::all();

    foreach (array_keys(AppendOnlyTables::TRUNCATABLE) as $table) {
        expect($appendOnly)->toContain($table);
    }
});

it('never lets the public record be truncatable', function () {
    // If a future change puts the record on that list, this fails and
    // somebody has to argue for it out loud.
    expect(AppendOnlyTables::isTruncatable('published_picks'))->toBeFalse()
        ->and(AppendOnlyTables::isTruncatable('pick_results'))->toBeFalse()
        ->and(AppendOnlyTables::all())->toContain('published_picks')
        ->and(AppendOnlyTables::all())->toContain('pick_results');
});

it('keeps the two lists disjoint', function () {
    $overlap = array_intersect(
        AppendOnlyTables::MUST_GUARD,
        array_keys(AppendOnlyTables::TRUNCATABLE),
    );

    expect($overlap)->toBe([]);
});

it('gives a reason for every table left truncatable', function () {
    expect(AppendOnlyTables::TRUNCATABLE)->not->toBeEmpty();

    foreach (AppendOnlyTables::TRUNCATABLE as $table => $reason) {
        // A reason short enough to be a shrug is not a reason, and this
        // is the one list in the codebase where that matters.
        expect($table)->toBeString()->not->toBeEmpty()
            ->and(mb_strlen($reason))->toBeGreaterThan(60);
    }
});

it('protects the quotes rather than the index built over them', function () {
    /*
     * odds_snapshots is the irreplaceable one: a quote exists only while
     * a book is showing it, so it cannot be re-fetched later at any
     * price, and the closing lines are computed from it.
     *
     * event_line_markers is where those closing lines are stored and is
     * deliberately unprotected - it is rebuilt by MarkClosingLines and
     * is not append-only. Asserted so that moving the concern back to
     * the cache has to be deliberate.
     */
    expect(AppendOnlyTables::mustGuard('odds_snapshots'))->toBeTrue()
        ->and(AppendOnlyTables::all())->toContain('odds_snapshots')
        ->and(AppendOnlyTables::mustGuard('event_line_markers'))->toBeFalse()
        ->and(AppendOnlyTables::all())->not->toContain('event_line_markers');
});
