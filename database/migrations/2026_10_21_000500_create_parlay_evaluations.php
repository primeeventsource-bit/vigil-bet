<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every parlay this app has judged, pick or pass, with the reason.
 *
 * Append-only like the rest of the evaluation history. The legs are frozen
 * onto the row as they stood, because the board moves and the numbers that
 * mattered are the ones the decision was made on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parlay_evaluations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('value_scan_id')->nullable()->constrained('value_scans')->nullOnDelete();

            // The legs exactly as evaluated: event, market, side, price,
            // model and fair probability, and the verdict of each market.
            $table->jsonb('legs');
            $table->unsignedSmallInteger('leg_count');
            // Null when the legs span more than one sport.
            $table->string('sport', 16)->nullable();

            // Two legs from one game are not independent, which makes the
            // joint probability and the hold both unstatable.
            $table->boolean('correlated')->default(false);

            $table->decimal('model_joint_prob', 10, 8)->nullable();
            $table->decimal('fair_joint_prob', 10, 8)->nullable();
            $table->decimal('shaded_joint_prob', 10, 8)->nullable();
            $table->decimal('shade_used', 6, 4);

            $table->integer('offered_price_american');
            $table->decimal('offered_decimal', 12, 4);
            $table->decimal('break_even', 10, 8);

            // What the book keeps, and the per-leg rate it compounds from.
            $table->decimal('hold', 8, 6)->nullable();
            $table->decimal('hold_per_leg', 8, 6)->nullable();

            $table->decimal('ev_per_dollar', 10, 6)->nullable();
            $table->decimal('ev_shaded', 10, 6)->nullable();

            $table->decimal('kelly_fraction', 8, 6)->nullable();
            $table->bigInteger('suggested_stake_cents')->default(0);
            $table->bigInteger('bankroll_cents')->default(0);

            $table->string('recommendation', 16);
            $table->string('pass_reason', 32)->nullable();
            $table->text('reason')->nullable();

            $table->timestampTz('evaluated_at');
            $table->timestampTz('created_at')->nullable();

            $table->index('evaluated_at');
            $table->index(['recommendation', 'evaluated_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(
                'CREATE TRIGGER parlay_evaluations_append_only
                 BEFORE UPDATE OR DELETE ON parlay_evaluations
                 FOR EACH ROW EXECUTE FUNCTION edge_deny_mutation();'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS parlay_evaluations_append_only ON parlay_evaluations;');
        }

        Schema::dropIfExists('parlay_evaluations');
    }
};
