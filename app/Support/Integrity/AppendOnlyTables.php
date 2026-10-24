<?php

declare(strict_types=1);

namespace App\Support\Integrity;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/**
 * Which append-only tables may still be truncated, and why.
 *
 * Append-only is enforced by a row-level BEFORE DELETE trigger, and that
 * does not fire on TRUNCATE in Postgres - only a statement-level BEFORE
 * TRUNCATE trigger does. The migration that adds those reads the set of
 * append-only tables straight out of `pg_trigger`, so coverage is
 * whatever the database currently guards and cannot drift from it. This
 * class holds the parts that are judgement rather than fact.
 *
 * It is a separate class so a test can hold it to account. An exception
 * list is the part of any guard that rots - a table quietly added here
 * stops being protected, and in a year nobody remembers it was ever
 * meant to be.
 */
final class AppendOnlyTables
{
    /**
     * Append-only tables deliberately left truncatable.
     *
     * Each needs a reason that survives being read by someone who was
     * not here. That is the only standard worth applying to a list of
     * exceptions to a safety guarantee.
     *
     * @var array<string, string>
     */
    public const TRUNCATABLE = [
        'shadow_picks' => 'An experiment, not a record. vigil:shadow-reset empties it and '
            .'refuses once anything has been settled, which protects the measurement '
            .'rather than the rows and is the stronger guarantee of the two.',

        'shadow_results' => 'Emptied together with shadow_picks, which holds its foreign '
            .'key, and covered by the same refusal once anything is settled.',

        'vl_board_entries' => 'A mirror of Edge Lab data, re-derivable by a sync. Cleared '
            .'by vigil:demo --fresh, which refuses to run in production.',

        'vl_events' => 'A mirror of Edge Lab data, re-derivable by a sync, cleared by the '
            .'same local-only demo command as vl_board_entries.',

        'vl_models' => 'A mirror of Edge Lab data, re-derivable by a sync, cleared by the '
            .'same local-only demo command as vl_board_entries.',

        'vl_odds_display' => 'A mirror of Edge Lab data, re-derivable by a sync, cleared '
            .'by the same local-only demo command as vl_board_entries.',
    ];

    /**
     * Tables a truncate must always be refused on.
     *
     * Not the whole guarded set - the migration derives that from the
     * database. These are the ones whose loss could not be undone, named
     * here so that excluding one has to survive a failing test and an
     * argument in a pull request.
     *
     * `odds_snapshots` is the least obvious entry and the most expensive
     * to lose. A quote exists only while a book is showing it, so these
     * cannot be re-fetched later at any price, and the closing lines
     * every verdict is measured against are computed from them. Losing
     * them would not slow the engine down, it would end its ability to
     * prove anything at all.
     *
     * `event_line_markers` is deliberately NOT here, which is worth
     * stating because it is where the closing lines actually live and
     * looks like the obvious candidate. It is a derived cache:
     * MarkClosingLines upserts it from `odds_snapshots`, so it can be
     * rebuilt, and the table is not append-only in the first place. The
     * thing that needs protecting is the source, not the index over it.
     *
     * The audit table is `audit_log`, which is what AuditEvent writes
     * to. Production also guards an `audit_events` table and a
     * `login_activity` one, neither of which has a model declaring the
     * trait - they are covered because the migration reads pg_trigger
     * rather than this file, which is the reason it does.
     *
     * @var array<int, string>
     */
    public const MUST_GUARD = [
        'published_picks',
        'pick_results',
        'odds_snapshots',
        'predictions',
        'value_bets',
        'bankroll_ledger',
        'bankroll_entries',
        'audit_log',
        'billing_events',
        'self_exclusions',
        'user_bet_events',
        'bet_events',
    ];

    /**
     * Every table behind a model that declares itself append-only.
     *
     * Discovered rather than listed, so that MUST_GUARD can be checked
     * against something independent of itself. Without this the test for
     * MUST_GUARD only asserted that its own entries were in its own
     * list, which is how `event_line_markers` sat on it while being
     * neither append-only nor guarded.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $tables = [];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $class = 'App\Models\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            if (! in_array(AppendOnly::class, class_uses_recursive($class), true)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $tables[] = (new $class)->getTable();
        }

        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }

    /** Whether a truncate must be refused on this table. */
    public static function mustGuard(string $table): bool
    {
        return in_array($table, self::MUST_GUARD, true);
    }

    /** Whether this table is deliberately left truncatable. */
    public static function isTruncatable(string $table): bool
    {
        return array_key_exists($table, self::TRUNCATABLE);
    }
}
