<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the product published, and whether it was right.
 *
 * Its own tables rather than a column on `published_picks`. That table
 * says of itself that it holds picks sold from a proven market and
 * nothing else, and the public record class reads it on that promise.
 * An opinion is a weaker claim - the model is well calibrated, not
 * better than the market - and mixing the two would mean the stronger
 * record could never be read without qualification again.
 *
 * Same guarantees as the record it sits beside, because it is a public
 * commitment and not an experiment:
 *
 *  - Append only. What was published is what was published.
 *  - Written before kickoff, enforced in the database. An opinion that
 *    could be recorded once the result was known would be worthless, and
 *    worse than worthless shown next to an accuracy figure.
 *  - One per selection, so a rescan cannot inflate the count.
 *  - Not truncatable. `shadow_picks` is, because it is an experiment
 *    with a documented reset; this is evidence and has no reset.
 *
 * `calibration_error_at_publish` is the unusual column and the important
 * one. It records how honest the model's probabilities were measured to
 * be at the moment this was published, so the accuracy page can say what
 * was known at the time rather than what is known now. A model that
 * drifts must not be able to rewrite the standing of opinions published
 * while it was good.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opinions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('board_entry_id')->nullable()
                ->constrained('vl_board_entries')->nullOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->string('sport')->index();
            $table->string('market')->index();
            $table->string('side')->nullable();
            $table->decimal('line', 8, 2)->nullable();
            $table->string('selection');
            $table->string('matchup')->nullable();

            $table->integer('price_american');
            $table->string('book')->nullable();
            $table->string('book_title')->nullable();

            // The confidence figure shown to a customer.
            $table->decimal('model_prob', 8, 6);
            $table->decimal('fair_prob', 8, 6)->nullable();
            $table->decimal('edge_at_price', 8, 5)->nullable();
            $table->decimal('stake_fraction', 8, 5)->nullable();

            $table->unsignedInteger('model_version')->nullable();

            /*
             * What the bar measured when this went out. Kept with the
             * row so the record can report the standard that was applied
             * rather than today's.
             */
            $table->decimal('calibration_error_at_publish', 8, 5)->nullable();
            $table->unsignedInteger('model_predictions_at_publish')->nullable();

            $table->timestampTz('kickoff_at')->index();
            $table->timestampTz('published_at')->index();
            $table->timestampTz('created_at');

            /*
             * One opinion per selection: a rescan must not publish the
             * same bet twice and count it twice.
             *
             * This index alone does not achieve that, and the trigger
             * below is what does. `line` is null on a moneyline, both
             * engines treat two NULLs as distinct in a unique index, and
             * moneylines are every market this publishes - so on its own
             * this constraint would wave through exactly the duplicates
             * it looks like it prevents. It stays as a backstop for
             * spreads, where the line is present.
             */
            $table->unique(['event_id', 'market', 'side', 'line'], 'opinions_selection_unique');
            $table->index(['sport', 'published_at']);
        });

        Schema::create('opinion_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opinion_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('status')->index();
            $table->integer('final_home_score')->nullable();
            $table->integer('final_away_score')->nullable();

            // Signed, per unit staked.
            $table->decimal('units', 10, 4);

            $table->integer('closing_price_american')->nullable();
            $table->decimal('clv', 8, 5)->nullable();

            $table->timestampTz('settled_at')->index();
            $table->timestampTz('created_at');
        });

        if (DB::getDriverName() === 'sqlite') {
            /*
             * SQLite cannot add a CHECK to an existing table, so the
             * pre-kickoff rule goes on as a trigger. Same guarantee, same
             * place - the database - which is the only version worth
             * having.
             */
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER opinions_before_kickoff_insert
                BEFORE INSERT ON opinions
                FOR EACH ROW WHEN NEW.published_at >= NEW.kickoff_at
                BEGIN
                    SELECT RAISE(ABORT, 'opinions: published_at must be before kickoff_at');
                END;

                CREATE TRIGGER opinions_one_per_selection
                BEFORE INSERT ON opinions
                FOR EACH ROW WHEN EXISTS (
                    SELECT 1 FROM opinions
                    WHERE event_id = NEW.event_id
                      AND market = NEW.market
                      AND side IS NEW.side
                      AND line IS NEW.line
                )
                BEGIN
                    SELECT RAISE(ABORT, 'opinions: this selection is already published');
                END;
            SQL);

            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['opinions', 'opinion_results'] as $table) {
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

                DROP TRIGGER IF EXISTS {$table}_no_truncate ON {$table};

                CREATE TRIGGER {$table}_no_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION vigil_refuse_truncate();
            SQL);
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE opinions
            ADD CONSTRAINT opinions_before_kickoff
            CHECK (published_at < kickoff_at);
        SQL);

        /*
         * The guard the unique index cannot be. `side` and `line` are
         * both nullable and Postgres treats two NULLs as distinct, so
         * the index above lets a second moneyline opinion through - and
         * moneylines are every market this publishes.
         *
         * `is not distinct from` is the null-aware comparison, which is
         * what the index is missing.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION vigil_opinions_one_per_selection()
            RETURNS trigger AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM opinions
                    WHERE event_id = NEW.event_id
                      AND market = NEW.market
                      AND side IS NOT DISTINCT FROM NEW.side
                      AND line IS NOT DISTINCT FROM NEW.line
                ) THEN
                    RAISE EXCEPTION 'opinions: this selection is already published';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS opinions_one_per_selection ON opinions;

            CREATE TRIGGER opinions_one_per_selection
            BEFORE INSERT ON opinions
            FOR EACH ROW EXECUTE FUNCTION vigil_opinions_one_per_selection();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('opinion_results');
        Schema::dropIfExists('opinions');

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS vigil_opinions_append_only();
            DROP FUNCTION IF EXISTS vigil_opinion_results_append_only();
        SQL);
    }
};
