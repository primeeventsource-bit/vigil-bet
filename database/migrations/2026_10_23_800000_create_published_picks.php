<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * APPEND ONLY, and never after kickoff.
 *
 * §7 calls the public record "the single most important trust and sales
 * asset", and this table is what makes it one. The claim is not "our
 * model is good"; it is "here is every pick we published, before the
 * game started, at the price that was available, and here is what
 * happened". A claim like that is only worth something if it cannot be
 * edited afterwards.
 *
 * So three guards, in the database rather than in the application:
 *
 *  - a CHECK constraint rejecting any row published at or after kickoff,
 *    which is §13's acceptance check and the one that makes the record
 *    impossible to pad with hindsight
 *  - a trigger rejecting UPDATE and DELETE
 *  - the price, the model probability and the stake fraction stored as
 *    they were at publication, not looked up later
 *
 * Nothing in here is a backtest or a paper result. §7 forbids mixing
 * them into one number, and the way to guarantee that is for this table
 * to contain only published picks and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('published_picks', function (Blueprint $table): void {
            $table->id();

            /*
             * The permanent URL. §7 asks for one per pick, and it has to
             * survive the row id changing - which it would if this table
             * were ever rebuilt from the event log.
             */
            $table->ulid('public_id')->unique();

            $table->foreignId('board_entry_id')->nullable()
                ->constrained('vl_board_entries')->nullOnDelete();
            $table->unsignedBigInteger('edge_lab_event_id')->index();

            $table->string('sport')->index();
            $table->string('market')->index();
            $table->string('side')->nullable();
            $table->decimal('line', 8, 2)->nullable();
            $table->string('selection');
            $table->string('matchup')->nullable();

            // The price that was actually available, at the book it was
            // available at. Stored, not looked up later.
            $table->integer('price_american');
            $table->string('book')->nullable();
            $table->string('book_title')->nullable();

            $table->decimal('model_prob', 8, 6)->nullable();
            $table->decimal('fair_prob', 8, 6)->nullable();
            $table->decimal('edge_at_price', 8, 5)->nullable();

            // What fraction of a bankroll the engine suggested. Part of
            // the record because a 2% edge staked at 10% is a different
            // claim from the same edge staked at 1%.
            $table->decimal('stake_fraction', 8, 5)->nullable();

            // Which model version said so, so a published pick can be
            // traced to the thing that produced it.
            $table->unsignedInteger('model_version')->nullable();

            $table->timestamp('kickoff_at');
            $table->timestamp('published_at');

            // The board entry as it stood. For the dispute nobody has
            // had yet, and for rebuilding the page if its shape changes.
            $table->json('payload')->nullable();

            $table->timestamp('created_at');

            $table->index(['published_at', 'sport']);
            $table->index('kickoff_at');
        });

        /*
         * §13: "A published pick timestamped after kickoff is rejected by
         * the database." Not by a validator, not by a model observer - by
         * the database, because that is the only place a constraint
         * cannot be forgotten by a console command, a seeder or a future
         * import script.
         *
         * SQLite honours CHECK too, so the guarantee holds in the test
         * suite as well as in production.
         */
        $check = 'ALTER TABLE published_picks ADD CONSTRAINT published_before_kickoff CHECK (published_at < kickoff_at)';

        if (DB::getDriverName() === 'pgsql') {
            DB::statement($check);

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION vigil_published_picks_append_only()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'published_picks is append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER published_picks_append_only
                BEFORE UPDATE OR DELETE ON published_picks
                FOR EACH ROW EXECUTE FUNCTION vigil_published_picks_append_only();
            SQL);
        }

        if (DB::getDriverName() === 'sqlite') {
            /*
             * SQLite cannot add a CHECK to an existing table, so the
             * constraint goes on as a trigger instead. Same guarantee,
             * same place - the database - which is what §13 asks for.
             */
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER published_picks_before_kickoff_insert
                BEFORE INSERT ON published_picks
                FOR EACH ROW WHEN NEW.published_at >= NEW.kickoff_at
                BEGIN
                    SELECT RAISE(ABORT, 'published_picks: published_at must be before kickoff_at');
                END;
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('published_picks');
    }
};
