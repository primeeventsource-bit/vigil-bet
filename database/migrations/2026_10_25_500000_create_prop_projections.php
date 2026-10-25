<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the prop model said before a game, and what actually happened.
 *
 * Not a published record. Nothing in here reaches a customer and the
 * schema still forbids a prop ever becoming a prediction or a pick -
 * `odds_snapshots` carries props in a column the model pipeline cannot
 * read, and that is deliberate. This is the evidence that could one day
 * earn them publication, and it has to exist first, because the gate is
 * circular otherwise: a market earns the right to claim an edge by
 * beating a closing line, and nothing can beat a closing line it was
 * never measured against.
 *
 * So three of the four guarantees `opinions` has, for the same reasons,
 * even though this is not public:
 *
 *  - Append only. A calibration record that can be edited measures
 *    nothing. This is the whole point of the table.
 *  - Written before kickoff, enforced in the database. A projection
 *    recorded once the box score was in would score perfectly and mean
 *    nothing, and that failure is silent.
 *  - One per selection, so re-running the projector nightly cannot
 *    turn one opinion into thirty and make a small sample look large.
 *
 * And it is NOT truncatable, which `shadow_picks` is. That table is an
 * experiment with a documented reset; this is the only thing that could
 * ever open the gate, and resetting it would throw away the months of
 * waiting that are the actual cost of opening it honestly.
 *
 * `model_params` is the unusual column. There is no `model_versions` row
 * behind a prop projection - it comes from a service and a handful of
 * config values rather than from fitted coefficients - so each row
 * carries the parameters that produced it and a fingerprint of them.
 * Without that, changing a half-life silently pools two different
 * models' predictions into one calibration figure, and the figure
 * describes neither.
 *
 * One row per selection rather than one per side, holding all three
 * probabilities. Over, under and push are one statement about one line,
 * and splitting them into two rows would let half a projection be
 * graded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prop_projections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->string('sport', 16)->index();
            $table->string('market_key', 48)->index();

            /*
             * The normalised join key as well as the name. The key is
             * what matched a box score to a price and is the thing to
             * group by; the name is what the odds feed actually wrote,
             * kept so a bad match can be recognised by eye later.
             */
            $table->string('player_key', 120)->index();
            $table->string('player_name', 120);
            $table->string('player_external_id', 64)->nullable();

            $table->decimal('line', 8, 2);

            // What the model said.
            $table->decimal('projected_mean', 10, 4);
            $table->decimal('projected_sd', 10, 4);
            $table->decimal('prob_over', 8, 6);
            $table->decimal('prob_under', 8, 6);
            $table->decimal('prob_push', 8, 6);

            /*
             * The same question answered without any distribution: the
             * weighted share of his game log that cleared this line.
             * Stored so the family can be put on trial against the data
             * it was chosen to describe, rather than only against the
             * market.
             */
            $table->decimal('empirical_over', 8, 6)->nullable();

            $table->string('family', 16);
            $table->unsignedInteger('games');
            $table->decimal('effective_games', 8, 2);
            $table->timestampTz('measured_from')->nullable();
            $table->timestampTz('measured_to')->nullable();

            /*
             * The market at the moment of the projection, vig removed
             * per book and only then combined. Null where no book quoted
             * both sides - which is every anytime touchdown on the
             * board, since only the yes side is collected, so the vig
             * cannot be removed and there is no fair price to be had.
             */
            $table->decimal('fair_prob_over', 8, 6)->nullable();
            $table->unsignedInteger('book_count')->default(0);
            $table->integer('best_over_price_american')->nullable();
            $table->integer('best_under_price_american')->nullable();

            // Which model this was, since there is no version row to point at.
            $table->string('model_fingerprint', 32)->index();
            $table->jsonb('model_params')->nullable();

            $table->timestampTz('kickoff_at')->index();
            $table->timestampTz('projected_at')->index();
            $table->timestampTz('created_at');

            /*
             * A backstop, not the guarantee. Both engines treat two
             * NULLs as distinct in a unique index, and while `line` is
             * not nullable here `player_external_id` is, so this holds
             * for the columns that matter and the trigger below holds
             * the rest.
             */
            $table->unique(
                ['event_id', 'market_key', 'player_key', 'line', 'model_fingerprint'],
                'prop_projections_selection_unique'
            );

            $table->index(['sport', 'market_key', 'projected_at']);
        });

        Schema::create('prop_projection_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prop_projection_id')->unique()
                ->constrained()->cascadeOnDelete();

            /*
             * What he actually did, read from the box score by the same
             * reader the projection was built with. Null on a void.
             */
            $table->decimal('actual', 10, 4)->nullable();

            /*
             * over, under, push or void.
             *
             * Void is the one worth being careful about: a player who
             * did not take the field has no box score line, and most
             * books void a prop on him rather than settling it. Counting
             * that as an under would flatter this model specifically,
             * because it leans under - it would be scored right for a
             * reason it never predicted. So a void is recorded and then
             * excluded from calibration.
             */
            $table->string('outcome', 8)->index();

            $table->timestampTz('settled_at')->index();
            $table->timestampTz('created_at');
        });

        if (DB::getDriverName() === 'sqlite') {
            /*
             * SQLite cannot add a CHECK to an existing table, so the
             * pre-kickoff rule goes on as a trigger. Same guarantee in
             * the same place - the database - which is the only version
             * worth having.
             */
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER prop_projections_before_kickoff_insert
                BEFORE INSERT ON prop_projections
                FOR EACH ROW WHEN NEW.projected_at >= NEW.kickoff_at
                BEGIN
                    SELECT RAISE(ABORT, 'prop_projections: projected_at must be before kickoff_at');
                END;

                CREATE TRIGGER prop_projections_append_only_update
                BEFORE UPDATE ON prop_projections
                BEGIN
                    SELECT RAISE(ABORT, 'prop_projections is append-only');
                END;

                CREATE TRIGGER prop_projections_append_only_delete
                BEFORE DELETE ON prop_projections
                BEGIN
                    SELECT RAISE(ABORT, 'prop_projections is append-only');
                END;

                CREATE TRIGGER prop_projection_results_append_only_update
                BEFORE UPDATE ON prop_projection_results
                BEGIN
                    SELECT RAISE(ABORT, 'prop_projection_results is append-only');
                END;

                CREATE TRIGGER prop_projection_results_append_only_delete
                BEFORE DELETE ON prop_projection_results
                BEGIN
                    SELECT RAISE(ABORT, 'prop_projection_results is append-only');
                END;
            SQL);

            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['prop_projections', 'prop_projection_results'] as $name) {
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION vigil_{$name}_append_only()
                RETURNS trigger AS \$\$
                BEGIN
                    RAISE EXCEPTION '{$name} is append-only';
                END;
                \$\$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS {$name}_append_only ON {$name};

                CREATE TRIGGER {$name}_append_only
                BEFORE UPDATE OR DELETE ON {$name}
                FOR EACH ROW EXECUTE FUNCTION vigil_{$name}_append_only();

                DROP TRIGGER IF EXISTS {$name}_no_truncate ON {$name};

                CREATE TRIGGER {$name}_no_truncate
                BEFORE TRUNCATE ON {$name}
                FOR EACH STATEMENT EXECUTE FUNCTION vigil_refuse_truncate();
            SQL);
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE prop_projections
            ADD CONSTRAINT prop_projections_before_kickoff
            CHECK (projected_at < kickoff_at);

            ALTER TABLE prop_projections
            ADD CONSTRAINT prop_projections_probabilities_sum
            CHECK (prob_over + prob_under + prob_push BETWEEN 0.999 AND 1.001);

            ALTER TABLE prop_projection_results
            ADD CONSTRAINT prop_projection_results_outcome
            CHECK (outcome IN ('over', 'under', 'push', 'void'));
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('prop_projection_results');
        Schema::dropIfExists('prop_projections');

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS vigil_prop_projections_append_only();
            DROP FUNCTION IF EXISTS vigil_prop_projection_results_append_only();
        SQL);
    }
};
