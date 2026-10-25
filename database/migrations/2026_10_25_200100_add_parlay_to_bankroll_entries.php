<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point a bankroll entry at the parlay that caused it.
 *
 * The table already carries `user_bet_id` for straight bets. Without the
 * parlay equivalent, a stake or a return from a parlay would appear in
 * the ledger with a reason string and nothing to join to - so a disputed
 * balance could be read but not traced, which is most of what a ledger is
 * for.
 *
 * Nullable, because most entries are neither: a deposit belongs to no
 * wager at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bankroll_entries', function (Blueprint $table): void {
            $table->foreignId('user_parlay_id')->nullable()->after('user_bet_id')
                ->constrained('user_parlays')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bankroll_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_parlay_id');
        });
    }
};
