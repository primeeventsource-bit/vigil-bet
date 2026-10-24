<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One shadow pick per selection, enforced in the database.
 *
 * The recorder deduplicated on `value_bet_id`, and every scan mints a
 * fresh value_bets row for the same selection. The board is rescanned
 * hourly, so a single game the engine would have bet was written down
 * once an hour: 92 rows standing for 31 selections within six hours, and
 * every one of them would have been settled and counted separately.
 *
 * A record that counts one bet five times is not a weaker record, it is
 * a wrong one - units and CLV would both have been inflated by whatever
 * the rescan rate happened to be, which is a property of the scheduler
 * and nothing to do with betting.
 *
 * A unique index is the obvious guard and cannot be used: the duplicates
 * are already there, and `shadow_picks` refuses deletes by design. That
 * refusal is the point of the table, so the fix works around history
 * rather than rewriting it. A BEFORE INSERT trigger makes no claim about
 * rows already written while making the next one impossible.
 *
 * The 61 existing duplicates stay where they are. Readers take the
 * earliest row per selection - see ShadowPick::scopeCanonical() - so the
 * measurement is right without anything being deleted.
 *
 * `is not distinct from` rather than `=` on the line: for a moneyline
 * the line is null, and `null = null` is unknown, so plain equality
 * would wave every h2h duplicate straight through. SQLite's `is` has the
 * same null-aware meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER shadow_picks_one_per_selection
                BEFORE INSERT ON shadow_picks
                FOR EACH ROW WHEN EXISTS (
                    SELECT 1 FROM shadow_picks
                    WHERE event_id = NEW.event_id
                      AND market = NEW.market
                      AND side = NEW.side
                      AND line IS NEW.line
                )
                BEGIN
                    SELECT RAISE(ABORT, 'shadow_picks: this selection is already recorded');
                END;
            SQL);

            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION vigil_shadow_picks_one_per_selection()
            RETURNS trigger AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM shadow_picks
                    WHERE event_id = NEW.event_id
                      AND market = NEW.market
                      AND side = NEW.side
                      AND line IS NOT DISTINCT FROM NEW.line
                ) THEN
                    RAISE EXCEPTION 'shadow_picks: this selection is already recorded';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS shadow_picks_one_per_selection ON shadow_picks;

            CREATE TRIGGER shadow_picks_one_per_selection
            BEFORE INSERT ON shadow_picks
            FOR EACH ROW EXECUTE FUNCTION vigil_shadow_picks_one_per_selection();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS shadow_picks_one_per_selection');

            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS shadow_picks_one_per_selection ON shadow_picks;
            DROP FUNCTION IF EXISTS vigil_shadow_picks_one_per_selection();
        SQL);
    }
};
