<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fills out the two cross-book tables so the finders can actually write to
 * them.
 *
 * Both tables were laid down in Phase 1 with the columns the idea needed at
 * the time, and nothing has ever written a row to either. Building the
 * finders turned up what was missing, and almost all of it is about
 * suspicion rather than arithmetic: how old the quotes were, whether both
 * legs are at books with an account behind them, and - for a middle - the
 * hit rate the thing actually needs set against what the board says it will
 * get. A width and a cost alone make every middle look like a free roll.
 *
 * Added rather than redefined, because the original migration has shipped.
 * The new columns are nullable or defaulted so the change applies to a
 * table with rows in it, even though in practice both are empty.
 *
 * The append-only triggers already cover both tables, from
 * 2026_10_20_000900_add_integrity_guards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arbitrage_opportunities', function (Blueprint $table) {
            $table->string('sport', 16)->nullable()->after('event_id');

            // Both sides have to be at the same number, or it is not an
            // arbitrage - it is a middle, and belongs in the other table.
            $table->decimal('line', 6, 2)->nullable()->after('market');

            // What the split and the profit are quoted against. Not a
            // recommended stake: an arbitrage has no edge to size against.
            $table->bigInteger('reference_stake_cents')->default(0)->after('stake_split');
            $table->bigInteger('guaranteed_profit_cents')->default(0)->after('reference_stake_cents');

            // An arbitrage with one leg at a book I cannot reach is an
            // observation, not a bet.
            $table->boolean('placeable')->default(false)->after('guaranteed_profit_cents');

            // The age of the older leg: the single best predictor of an
            // arbitrage being a ghost left by one book lagging another.
            $table->unsignedInteger('oldest_quote_seconds')->default(0)->after('placeable');

            $table->unsignedBigInteger('job_run_id')->nullable()->after('oldest_quote_seconds');

            $table->index('detected_at', 'arb_detected_idx');
            $table->index(['placeable', 'detected_at'], 'arb_placeable_idx');
        });

        Schema::table('middle_opportunities', function (Blueprint $table) {
            $table->string('sport', 16)->nullable()->after('event_id');

            // Whole-number results strictly inside the gap: how a middle is
            // actually counted, and a push is not one of them.
            $table->unsignedSmallInteger('outs')->default(0)->after('width');

            $table->decimal('implied_sum', 10, 8)->nullable()->after('outs');
            // The hit rate that breaks even, which is the overround itself.
            $table->decimal('break_even_hit_prob', 10, 8)->nullable()->after('implied_sum');
            // What the board says the gap is worth. No model required.
            $table->decimal('market_hit_prob', 10, 8)->nullable()->after('break_even_hit_prob');

            $table->decimal('hit_pct', 8, 6)->nullable()->after('net_cost_pct');
            $table->decimal('ev_market', 10, 6)->nullable()->after('model_hit_prob');
            $table->decimal('ev_model', 10, 6)->nullable()->after('ev_market');

            $table->jsonb('stake_split')->nullable()->after('ev_model');
            $table->bigInteger('reference_stake_cents')->default(0)->after('stake_split');

            $table->boolean('placeable')->default(false)->after('reference_stake_cents');
            $table->unsignedInteger('oldest_quote_seconds')->default(0)->after('placeable');

            $table->unsignedBigInteger('job_run_id')->nullable()->after('oldest_quote_seconds');

            $table->index('detected_at', 'mid_detected_idx');
            $table->index(['placeable', 'detected_at'], 'mid_placeable_idx');
        });
    }

    public function down(): void
    {
        Schema::table('arbitrage_opportunities', function (Blueprint $table) {
            $table->dropIndex('arb_detected_idx');
            $table->dropIndex('arb_placeable_idx');
            $table->dropColumn([
                'sport', 'line', 'reference_stake_cents', 'guaranteed_profit_cents',
                'placeable', 'oldest_quote_seconds', 'job_run_id',
            ]);
        });

        Schema::table('middle_opportunities', function (Blueprint $table) {
            $table->dropIndex('mid_detected_idx');
            $table->dropIndex('mid_placeable_idx');
            $table->dropColumn([
                'sport', 'outs', 'implied_sum', 'break_even_hit_prob', 'market_hit_prob',
                'hit_pct', 'ev_market', 'ev_model', 'stake_split', 'reference_stake_cents',
                'placeable', 'oldest_quote_seconds', 'job_run_id',
            ]);
        });
    }
};
