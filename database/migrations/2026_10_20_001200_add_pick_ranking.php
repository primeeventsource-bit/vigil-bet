<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two ways to order the same picks, and a floor on win probability.
 *
 * Both orderings are stored on every scan so the board can switch between
 * them without rescanning and without the numbers shifting underneath.
 *
 * Ordering is not selection. A candidate that failed the value test is never
 * promoted by either ranking; these columns only order what already passed.
 * Sorting by likelihood alone would put -300 favourites on top, which is how
 * a tool that looks confident loses money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bankroll_settings', function (Blueprint $table) {
            $table->decimal('min_win_probability', 8, 6)->default(0.55)->after('edge_threshold');
        });

        Schema::table('value_scans', function (Blueprint $table) {
            $table->string('rank_mode', 24)->default('most_likely')->after('settings');
        });

        Schema::table('value_bets', function (Blueprint $table) {
            $table->unsignedSmallInteger('probability_rank')->nullable()->after('rank');
            $table->unsignedSmallInteger('value_rank')->nullable()->after('probability_rank');
        });
    }

    public function down(): void
    {
        Schema::table('value_bets', function (Blueprint $table) {
            $table->dropColumn(['probability_rank', 'value_rank']);
        });

        Schema::table('value_scans', function (Blueprint $table) {
            $table->dropColumn('rank_mode');
        });

        Schema::table('bankroll_settings', function (Blueprint $table) {
            $table->dropColumn('min_win_probability');
        });
    }
};
