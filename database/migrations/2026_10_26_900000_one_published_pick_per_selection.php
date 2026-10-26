<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One published pick per selection, enforced where it cannot be forgotten.
 *
 * The publisher deduplicated on the board row that proposed a pick. The
 * board is rescanned hourly and mints a new row for the same game every
 * time, so the identical claim went back on the record every hour: by the
 * time this was written the live record held seventeen rows that were
 * eight real selections, one of them published three times in a day.
 *
 * The publisher now keys on the selection, which stops it happening
 * again. This is the half the publisher cannot do: a check performed
 * before a write is not a constraint, and two publishers running at the
 * same instant would still both pass it. §7 calls the record the product's
 * most important trust asset, and a record that can be padded by a race is
 * not one.
 *
 * Follows `opinions`, which met this exact problem first and solved it
 * with an index and a trigger together - the index cannot do it alone,
 * for the reason written on the trigger below.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->removeTheDuplicatesAlreadyWritten();

        Schema::table('published_picks', function (Blueprint $table): void {
            /*
             * A backstop, and honestly only that. `side` and `line` are
             * both nullable, both engines treat two NULLs as distinct in
             * a unique index, and a moneyline has no line - so on its own
             * this waves through most of the duplicates it looks like it
             * prevents. It earns its place on spreads and totals, where
             * the line is a real number. The trigger does the rest.
             */
            $table->unique(
                ['edge_lab_event_id', 'market', 'side', 'line'],
                'published_picks_selection_unique',
            );
        });

        $this->addTheNullAwareGuard();
    }

    /**
     * Clear what the old key let through, keeping the first of each.
     *
     * The earliest row is the honest one: it holds the price that was
     * actually on the board when the claim was first made, and the later
     * copies are the same claim re-timestamped against a drifted price.
     *
     * A duplicate that has already been graded is left alone. Its result
     * would cascade away with it, and a recorded outcome is not something
     * to delete quietly in a migration - if two copies of one selection
     * are ever both settled, the unique index below fails loudly and a
     * person decides, which is the right way for that to go.
     */
    private function removeTheDuplicatesAlreadyWritten(): void
    {
        $postgres = DB::getDriverName() === 'pgsql';

        // The table refuses deletes, as it should. Lifted for exactly as
        // long as this takes and put straight back.
        if ($postgres) {
            DB::unprepared('DROP TRIGGER IF EXISTS published_picks_append_only ON published_picks;');
        }

        try {
            DB::statement(<<<'SQL'
                DELETE FROM published_picks
                WHERE id NOT IN (
                    SELECT MIN(id) FROM published_picks
                    GROUP BY edge_lab_event_id, market, side, line
                )
                AND id NOT IN (SELECT published_pick_id FROM pick_results)
            SQL);
        } finally {
            if ($postgres) {
                DB::unprepared(<<<'SQL'
                    CREATE TRIGGER published_picks_append_only
                    BEFORE UPDATE OR DELETE ON published_picks
                    FOR EACH ROW EXECUTE FUNCTION vigil_published_picks_append_only();
                SQL);
            }
        }
    }

    /**
     * The guard the unique index cannot be.
     *
     * `is not distinct from` on Postgres and `is` on SQLite are the
     * null-aware comparisons a unique index does not make.
     */
    private function addTheNullAwareGuard(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION vigil_published_picks_one_per_selection()
                RETURNS trigger AS $$
                BEGIN
                    IF EXISTS (
                        SELECT 1 FROM published_picks
                        WHERE edge_lab_event_id = NEW.edge_lab_event_id
                          AND market = NEW.market
                          AND side IS NOT DISTINCT FROM NEW.side
                          AND line IS NOT DISTINCT FROM NEW.line
                    ) THEN
                        RAISE EXCEPTION 'published_picks: this selection is already published';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS published_picks_one_per_selection ON published_picks;

                CREATE TRIGGER published_picks_one_per_selection
                BEFORE INSERT ON published_picks
                FOR EACH ROW EXECUTE FUNCTION vigil_published_picks_one_per_selection();
            SQL);
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER published_picks_one_per_selection
                BEFORE INSERT ON published_picks
                FOR EACH ROW WHEN EXISTS (
                    SELECT 1 FROM published_picks
                    WHERE edge_lab_event_id = NEW.edge_lab_event_id
                      AND market = NEW.market
                      AND side IS NEW.side
                      AND line IS NEW.line
                )
                BEGIN
                    SELECT RAISE(ABORT, 'published_picks: this selection is already published');
                END;
            SQL);
        }
    }

    public function down(): void
    {
        // Forward-only by policy; this is here so a local rebuild works.
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS published_picks_one_per_selection ON published_picks;');
            DB::unprepared('DROP FUNCTION IF EXISTS vigil_published_picks_one_per_selection();');
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS published_picks_one_per_selection;');
        }

        Schema::table('published_picks', function (Blueprint $table): void {
            $table->dropUnique('published_picks_selection_unique');
        });
    }
};
