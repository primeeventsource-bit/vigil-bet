<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the original single `rank` column.
 *
 * Once both orderings exist, a bare `rank` is ambiguous: it silently means
 * whichever mode the scan happened to run in, so reading it without also
 * reading rank_mode gives a plausible wrong answer. Two explicitly named
 * columns cannot be misread.
 *
 * Nothing has written to it yet - the value finder is not built - so this
 * drops cleanly rather than needing a backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('value_bets', function (Blueprint $table) {
            $table->dropIndex('value_bets_scan_rank_idx');
            $table->dropColumn('rank');
            $table->index(['value_scan_id', 'recommendation', 'probability_rank'], 'value_bets_scan_likely_idx');
            $table->index(['value_scan_id', 'recommendation', 'value_rank'], 'value_bets_scan_value_idx');
        });
    }

    public function down(): void
    {
        Schema::table('value_bets', function (Blueprint $table) {
            $table->dropIndex('value_bets_scan_likely_idx');
            $table->dropIndex('value_bets_scan_value_idx');
            $table->unsignedSmallInteger('rank')->nullable()->after('recommendation');
            $table->index(['value_scan_id', 'recommendation', 'rank'], 'value_bets_scan_rank_idx');
        });
    }
};
