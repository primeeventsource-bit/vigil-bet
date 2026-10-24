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
| What they can do is hold the exception list to account, which is the
| part that rots. Coverage itself cannot drift: the migration reads the
| append-only tables out of pg_trigger, so it guards whatever the
| database guards. The judgement is which ones to leave out.
|
*/

it('never lets the public record be truncatable', function () {
    /*
     * The test that matters. If a future change puts the record on that
     * list, this fails and somebody has to argue for it out loud.
     */
    expect(AppendOnlyTables::isTruncatable('published_picks'))->toBeFalse()
        ->and(AppendOnlyTables::isTruncatable('pick_results'))->toBeFalse();
});

it('requires a guard on everything whose loss could not be undone', function () {
    foreach (AppendOnlyTables::MUST_GUARD as $table) {
        expect(AppendOnlyTables::mustGuard($table))->toBeTrue()
            // Belt and braces: the two lists must never overlap, or the
            // guard would be required and excluded at the same time.
            ->and(AppendOnlyTables::isTruncatable($table))->toBeFalse();
    }
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

it('names the closing lines among the tables that must be guarded', function () {
    /*
     * Called out on its own because it is the least obvious entry and
     * the most expensive to lose. A closing line exists only in the
     * minutes before kickoff, so these cannot be re-fetched later at any
     * price - and they are the benchmark every verdict is measured
     * against, so losing them does not slow the engine down, it stops it
     * being able to prove anything at all.
     */
    expect(AppendOnlyTables::mustGuard('odds_snapshots'))->toBeTrue()
        ->and(AppendOnlyTables::mustGuard('event_line_markers'))->toBeTrue();
});
