<?php

declare(strict_types=1);

namespace App\Support\Integrity;

/**
 * Which append-only tables may still be truncated, and why.
 *
 * Append-only is enforced by a row-level BEFORE DELETE trigger, and that
 * does not fire on TRUNCATE in Postgres - only a statement-level BEFORE
 * TRUNCATE trigger does. The migration that adds those reads the set of
 * append-only tables straight out of `pg_trigger`, so coverage is
 * whatever the database currently guards and cannot drift from it. This
 * class holds the one part that is a judgement rather than a fact: the
 * tables deliberately left truncatable.
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
     * The odds snapshots belong on this list as much as the record does.
     * A closing line exists only in the minutes before kickoff, so
     * emptying them is not a setback that time repairs: it is the
     * permanent loss of the only thing any verdict can be measured
     * against.
     *
     * @var array<int, string>
     */
    public const MUST_GUARD = [
        'published_picks',
        'pick_results',
        'odds_snapshots',
        'predictions',
        'value_bets',
        'event_line_markers',
        'bankroll_ledger',
        'bankroll_entries',
        'audit_events',
        'billing_events',
        'self_exclusions',
        'user_bet_events',
        'bet_events',
    ];

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
