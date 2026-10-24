<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The append-only triggers that were never written.
 *
 * Seven tables carry the AppendOnly trait and so refuse updates and
 * deletes in PHP, but had no database trigger behind them: the four Edge
 * Lab mirrors, vigil_reads, ai_usage's sibling audit_log, and - worst of
 * the set - `audit_log` itself.
 *
 * That last one matters more than the rest put together. The audit log is
 * the table whose entire purpose is to be evidence of what somebody did,
 * and a guard that lives only in an Eloquent event can be bypassed by
 * anything that does not go through Eloquent: a raw query, a seeder, a
 * tinker one-liner, a future import script. The thing the log protects
 * against is precisely somebody wanting to change it.
 *
 * docs/STATUS.md claimed at step 5 that audit_log refused writes "in the
 * model and in Postgres". The first half was true. This makes the second
 * half true as well, and the step 12 note says so rather than quietly
 * fixing it.
 *
 * Found by the sweep in tests/Feature/Hardening/AppendOnlyTest.php, which
 * derives the list from the models rather than from a list somebody
 * maintains - which is why it found something a hand-kept list had
 * missed for seven steps.
 */
return new class extends Migration
{
    /**
     * Tables whose model is append-only but whose database was not.
     *
     * @var array<int, string>
     */
    private const TABLES = [
        'vl_events',
        'vl_board_entries',
        'vl_odds_display',
        'vl_models',
        'vigil_reads',
        'audit_log',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            // SQLite gets the trait and nothing else, which is the same
            // position every other append-only table is in there.
            return;
        }

        foreach (self::TABLES as $table) {
            /*
             * Guarded so this migration is safe on a database where some
             * of them already exist - the earlier migrations may have
             * been edited by the time anybody runs this from scratch.
             */
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION vigil_{$table}_append_only()
                RETURNS trigger AS \$\$
                BEGIN
                    RAISE EXCEPTION '{$table} is append-only';
                END;
                \$\$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS {$table}_append_only ON {$table};

                CREATE TRIGGER {$table}_append_only
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION vigil_{$table}_append_only();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_append_only ON {$table};");
            DB::unprepared("DROP FUNCTION IF EXISTS vigil_{$table}_append_only();");
        }
    }
};
