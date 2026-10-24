<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The column called avg_clv_pct never held closing line value.
 *
 * It held the mean gap between the market's fair home probability and the
 * model's, which is a measure of which way a model leans on home teams. CLV
 * needs two prices - the one a bet was struck at and the later close - and a
 * backtest over closing lines only ever has one.
 *
 * The values already stored are perfectly good home-bias numbers, so they
 * are kept and the column is renamed to say what they are. Nothing is
 * recomputed and nothing is thrown away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backtest_runs', function (Blueprint $table) {
            $table->renameColumn('avg_clv_pct', 'mean_home_bias');
        });
    }

    public function down(): void
    {
        Schema::table('backtest_runs', function (Blueprint $table) {
            $table->renameColumn('mean_home_bias', 'avg_clv_pct');
        });
    }
};
