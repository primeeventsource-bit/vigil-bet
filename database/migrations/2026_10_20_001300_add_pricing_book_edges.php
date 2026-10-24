<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two different edges, kept apart on purpose.
 *
 *  edge_at_price - the model win chance minus the break-even rate at the
 *                  price actually on offer at the pricing book. This is the
 *                  one that decides whether a bet makes money, and the one
 *                  that gates a pick.
 *
 *  edge          - the model minus the no-vig fair probability across the
 *                  whole market. This says whether the model disagrees with
 *                  the sharp consensus, which is a statement about the model,
 *                  not about this bet.
 *
 * Collapsing them into one number is how a tool ends up recommending a bet
 * that is theoretically sound and practically unprofitable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('value_scans', function (Blueprint $table) {
            $table->unsignedBigInteger('pricing_book_id')->nullable()->after('rank_mode');
        });

        Schema::table('value_bets', function (Blueprint $table) {
            $table->decimal('break_even_win_rate', 8, 6)->nullable()->after('fair_prob');
            $table->decimal('edge_at_price', 8, 6)->nullable()->after('break_even_win_rate');
            $table->boolean('better_elsewhere')->default(false)->after('market_best_price_american');
        });
    }

    public function down(): void
    {
        Schema::table('value_bets', function (Blueprint $table) {
            $table->dropColumn(['break_even_win_rate', 'edge_at_price', 'better_elsewhere']);
        });

        Schema::table('value_scans', function (Blueprint $table) {
            $table->dropColumn('pricing_book_id');
        });
    }
};
