<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The shadow record: what the engine would have bet, had the gate let it.
 *
 * Every market is currently unproven, so the board is entirely passes and
 * the public record is empty. That is correct and it is also a dead end:
 * a market can only clear the verdict gate by accumulating graded
 * predictions, and nothing here accumulates evidence about whether the
 * engine's own picks would have won.
 *
 * So this table collects them. Same discipline as published_picks -
 * written before kickoff, never edited, settled against the real result
 * with closing line value - and kept completely apart from it:
 *
 *  - a different table, so no query that builds the public record can
 *    reach these rows by accident;
 *  - never shown to a customer, only behind the admin gate;
 *  - and labelled, everywhere it appears, as what it is.
 *
 * The one thing it must never become is a track record. A shadow pick was
 * not published, nobody could have bet it, and the moment these numbers
 * are presented as results the product is doing the exact thing its front
 * page says it does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The scanner's own answer to "would this have been a bet",
         * computed in the same pass as the real decision so there is never
         * a second implementation of what counts as one.
         *
         * Null on every row written before this migration, which is
         * honest: those scans never asked the question.
         */
        Schema::table('value_bets', function (Blueprint $table): void {
            $table->string('shadow_recommendation', 8)->nullable()->after('recommendation');
            $table->string('shadow_pass_reason', 32)->nullable()->after('shadow_recommendation');

            $table->index(['shadow_recommendation', 'flagged_at'], 'value_bets_shadow_idx');
        });

        Schema::create('shadow_picks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('value_bet_id')->unique()
                ->constrained('value_bets')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->string('sport')->index();
            $table->string('market')->index();
            $table->string('side')->nullable();
            $table->decimal('line', 8, 2)->nullable();
            $table->string('selection');
            $table->string('matchup')->nullable();

            // The price that was actually on the board, at the book it was
            // on. Stored rather than looked up later, for the same reason
            // the real record stores it: a price fetched afterwards is a
            // claim about a number nobody was offered.
            $table->integer('price_american');
            $table->string('book')->nullable();

            $table->decimal('model_prob', 8, 6)->nullable();
            $table->decimal('fair_prob', 8, 6)->nullable();
            $table->decimal('edge_at_price', 8, 5)->nullable();
            $table->decimal('stake_fraction', 8, 5)->nullable();

            /*
             * What the real gate said at the time. The whole point of the
             * experiment is that these were declined, so the reason has to
             * travel with the row - a shadow record that forgot why it was
             * a shadow would read as a record.
             */
            $table->string('market_verdict', 24)->nullable();
            $table->unsignedInteger('model_version')->nullable();

            $table->timestamp('kickoff_at');
            $table->timestamp('recorded_at');
            $table->timestamp('created_at');

            $table->index(['recorded_at', 'sport']);
            $table->index('kickoff_at');
        });

        Schema::create('shadow_results', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('shadow_pick_id')->unique()
                ->constrained()->cascadeOnDelete();

            $table->string('status')->index();

            $table->integer('final_home_score')->nullable();
            $table->integer('final_away_score')->nullable();

            // Signed profit per unit staked. Units, not money: nobody was
            // told to stake anything, because nobody was shown these.
            $table->decimal('units', 10, 4);

            $table->integer('closing_price_american')->nullable();
            $table->decimal('clv', 8, 5)->nullable();

            $table->timestamp('settled_at')->index();
            $table->timestamp('created_at');
        });

        if (DB::getDriverName() === 'sqlite') {
            /*
             * SQLite cannot add a CHECK to an existing table, so the
             * constraint goes on as a trigger instead. Same guarantee and
             * the same place - the database - which is the only version of
             * this guard worth having.
             */
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER shadow_picks_before_kickoff_insert
                BEFORE INSERT ON shadow_picks
                FOR EACH ROW WHEN NEW.recorded_at >= NEW.kickoff_at
                BEGIN
                    SELECT RAISE(ABORT, 'shadow_picks: recorded_at must be before kickoff_at');
                END;
            SQL);
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        /*
         * Append-only, and recorded before kickoff, enforced in the
         * database rather than only in the application.
         *
         * This matters more here than almost anywhere else in the schema.
         * A shadow record exists to answer "would these have won", and a
         * row that can be edited afterwards, or added once the result is
         * known, answers nothing at all. The guard is what makes the
         * experiment worth running.
         */
        foreach (['shadow_picks', 'shadow_results'] as $table) {
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

        DB::unprepared(<<<'SQL'
            ALTER TABLE shadow_picks
            ADD CONSTRAINT shadow_picks_before_kickoff
            CHECK (recorded_at < kickoff_at);
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('shadow_results');
        Schema::dropIfExists('shadow_picks');

        Schema::table('value_bets', function (Blueprint $table): void {
            $table->dropIndex('value_bets_shadow_idx');
            $table->dropColumn(['shadow_recommendation', 'shadow_pass_reason']);
        });
    }
};
