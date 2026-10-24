<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Absolute per-bet bounds, alongside the percentage cap.
 *
 * The percentage cap answers "what is safe for this bankroll". These answer
 * "what will I actually place at a window": a floor below which a bet is not
 * worth the effort, and a ceiling I will not exceed regardless of what Kelly
 * says on a day it likes something a lot.
 *
 * Forward-only: bankroll_settings is append-only and versioned, so existing
 * rows keep their history and get sensible bounds rather than being rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bankroll_settings', function (Blueprint $table) {
            $table->bigInteger('min_stake_cents')->default(0)->after('unit_size_cents');
            $table->bigInteger('max_stake_cents')->nullable()->after('min_stake_cents');
        });
    }

    public function down(): void
    {
        Schema::table('bankroll_settings', function (Blueprint $table) {
            $table->dropColumn(['min_stake_cents', 'max_stake_cents']);
        });
    }
};
