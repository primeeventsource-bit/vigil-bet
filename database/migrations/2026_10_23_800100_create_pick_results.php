<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * APPEND ONLY. What happened to a published pick.
 *
 * Separate from the pick rather than columns on it, for one reason: the
 * pick row must never be written to again after publication, and a result
 * arrives hours later. Two tables is what lets both be append-only.
 *
 * One result per pick, enforced by a unique key. A pick settled twice
 * would appear twice in a record, and a record that can be padded by
 * re-running a command is not a record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pick_results', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('published_pick_id')->unique()
                ->constrained()->cascadeOnDelete();

            // won / lost / push / void. Push and void both return the
            // stake and only the push is a tested prediction.
            $table->string('status')->index();

            $table->integer('final_home_score')->nullable();
            $table->integer('final_away_score')->nullable();

            /*
             * Profit per unit staked, signed. Units rather than dollars
             * because a public record in dollars would imply a stake
             * size nobody was told to use.
             */
            $table->decimal('units', 10, 4);

            /*
             * Closing line value, from a quote stored before kickoff.
             * §7 asks for it on the public record, and it is the figure
             * that distinguishes a model from a hot streak.
             */
            $table->integer('closing_price_american')->nullable();
            $table->decimal('clv', 8, 5)->nullable();

            $table->timestamp('settled_at')->index();
            $table->timestamp('created_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION vigil_pick_results_append_only()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'pick_results is append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER pick_results_append_only
                BEFORE UPDATE OR DELETE ON pick_results
                FOR EACH ROW EXECUTE FUNCTION vigil_pick_results_append_only();
            SQL);
        }
    }
};
