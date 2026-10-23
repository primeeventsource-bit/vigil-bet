<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per sync attempt, success or failure. No silent failures.
 *
 * This table is what the sync-health page reads and what makes "data
 * delayed" honest rather than a guess. A feed that stopped answering looks
 * exactly like a quiet night until you can see when it last succeeded -
 * which is the difference between a board with nothing on it and a board
 * that is simply out of date, and the customer is owed that distinction.
 *
 * The cursor lives here too. A keyset sync has to remember where it got
 * to, and keeping that beside the run that advanced it means an operator
 * can see both at once: how far along the feed is, and whether the last
 * attempt to move it worked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            // board, events, models, odds
            $table->string('feed', 32);
            // success, failed, skipped
            $table->string('status', 16);
            $table->unsignedInteger('records_fetched')->default(0);
            // Fetched and genuinely new. The gap between the two is how
            // much of a sync was a re-read of what we already had.
            $table->unsignedInteger('records_written')->default(0);
            $table->unsignedBigInteger('cursor_before')->nullable();
            $table->unsignedBigInteger('cursor_after')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('pages')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->index(['feed', 'started_at']);
            $table->index(['feed', 'status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
