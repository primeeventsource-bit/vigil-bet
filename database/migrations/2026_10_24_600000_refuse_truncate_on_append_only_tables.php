<?php

declare(strict_types=1);

use App\Support\Integrity\AppendOnlyTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Append-only stops a delete and does not stop a truncate.
 *
 * A row-level BEFORE DELETE trigger does not fire on TRUNCATE in
 * Postgres - only a statement-level BEFORE TRUNCATE trigger does, and
 * there were none. So every one of the 35 tables carrying an append-only
 * guard could be emptied by a single statement, including
 * `published_picks`, which is the table the product's own front page
 * points at when it says the record contains published picks and nothing
 * else.
 *
 * This was found by emptying the shadow record, which worked precisely
 * because of the gap: nothing had to be dropped to do it. That was
 * convenient there and is not a property worth having on the record.
 *
 * The tables deliberately left out live in AppendOnlyTables::TRUNCATABLE,
 * each with its reason, where a test can hold the list to account. They
 * are left out because a guard nobody can work with gets dropped the
 * first time it is inconvenient, and a guard dropped under pressure is
 * worse than one that was never claimed:
 *
 *  - `shadow_picks` and `shadow_results`. An experiment, not a record,
 *    with a documented reset path in `vigil:shadow-reset` that refuses
 *    once anything has been settled. That refusal is the real guarantee
 *    there, and it is a stronger one than this trigger: it protects the
 *    measurement rather than the rows.
 *
 *  - `vl_board_entries`, `vl_events`, `vl_models`, `vl_odds_display`.
 *    Mirrors of Edge Lab data, re-derivable by a sync, cleared by
 *    `vigil:demo --fresh` for local design review. Nothing is lost by
 *    emptying them and that command already refuses to run in
 *    production.
 *
 * Everything else is evidence that cannot be reconstructed. The odds
 * snapshots in particular: a closing line exists only in the minutes
 * before kickoff, so a truncate there is not a setback, it is the
 * permanent loss of the only thing any verdict can be measured against.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            /*
             * SQLite has no TRUNCATE - Laravel's truncate() issues a
             * DELETE there - and never had the append-only triggers
             * either, which are pgsql-only in the migrations that made
             * them. Nothing to guard and nothing to pretend to guard.
             */
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION vigil_refuse_truncate()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    '% is append-only: truncate is refused, as delete is',
                    TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        foreach ($this->guarded() as $table) {
            DB::unprepared(<<<SQL
                DROP TRIGGER IF EXISTS {$table}_no_truncate ON {$table};

                CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION vigil_refuse_truncate();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->guarded() as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_truncate ON {$table}");
        }

        DB::unprepared('DROP FUNCTION IF EXISTS vigil_refuse_truncate()');
    }

    /**
     * The append-only tables, as the database reports them.
     *
     * Read from pg_trigger rather than written out by hand so that the
     * two lists cannot drift: a table carrying an append-only guard is
     * exactly a table that should refuse a truncate, and asking the
     * database which those are is the only way to be sure the answer is
     * current.
     *
     * @return array<int, string>
     */
    private function guarded(): array
    {
        $tables = DB::select(<<<'SQL'
            select distinct c.relname as table_name
            from pg_trigger t
            join pg_class c on c.oid = t.tgrelid
            where not t.tgisinternal
              and t.tgname like '%append_only%'
            order by c.relname
        SQL);

        return array_values(array_diff(
            array_map(static fn (object $row): string => $row->table_name, $tables),
            array_keys(AppendOnlyTables::TRUNCATABLE),
        ));
    }
};
