<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schedule facts known before kickoff: days of rest each side had, whether
 * the roof is closed, the surface, and whether it is a division game.
 *
 * These are the only kind of context worth storing here, because they are
 * knowable in advance. Game-time temperature and wind are recorded by the
 * source after the fact, and training on them would be leakage dressed up
 * as a weather feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedSmallInteger('home_rest_days')->nullable()->after('week');
            $table->unsignedSmallInteger('away_rest_days')->nullable()->after('home_rest_days');
            $table->string('roof', 16)->nullable()->after('away_rest_days');
            $table->string('surface', 24)->nullable()->after('roof');
            $table->boolean('divisional')->nullable()->after('surface');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['home_rest_days', 'away_rest_days', 'roof', 'surface', 'divisional']);
        });
    }
};
