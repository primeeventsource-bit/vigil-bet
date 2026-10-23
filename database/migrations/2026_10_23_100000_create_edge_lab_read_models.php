<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vigil's own copy of what Edge Lab said, and when it said it.
 *
 * APPEND ONLY, all four. Nothing here is updated in place, including
 * events - which Edge Lab itself treats as its one mutable table, because
 * a game genuinely changes state. Vigil writes a new row for each state it
 * observes instead, and current state is the newest row for an id. That
 * costs a subquery and buys the thing the product is sold on: when a pick
 * is published, what the engine believed at that moment is still on disk
 * and cannot be quietly rewritten by a later sync.
 *
 * A row is written only when something actually changed. Every table
 * carries a `digest` of the payload, and a sync seeing the same values
 * again writes nothing - otherwise an hourly tick against a quiet Tuesday
 * would add a copy of the whole board every hour and the history would be
 * mostly noise.
 *
 * `edge_lab_id` is the engine's own primary key. It is the join between
 * the two systems and the reason a re-sync is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Schedule, teams, status, final score.
        Schema::create('vl_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('edge_lab_id');
            $table->string('sport', 16);
            $table->timestamp('starts_at')->nullable();
            $table->string('status', 24)->nullable();
            $table->string('home_name')->nullable();
            $table->string('home_abbreviation', 12)->nullable();
            $table->string('away_name')->nullable();
            $table->string('away_abbreviation', 12)->nullable();
            $table->integer('final_home_score')->nullable();
            $table->integer('final_away_score')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('payload');
            $table->char('digest', 64);
            $table->timestamp('observed_at');

            $table->index(['edge_lab_id', 'id']);
            $table->index(['sport', 'starts_at']);
            $table->index('observed_at');
        });

        /*
         * The value board: every evaluated candidate, pick and pass.
         *
         * Deliberately wide rather than one blob. These columns are what
         * the board is filtered and sorted by, and a product that has to
         * parse JSON to answer "show me today's picks" ends up recomputing
         * the answer, which is the one thing Vigil must never do.
         */
        Schema::create('vl_board_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('edge_lab_id');
            $table->unsignedBigInteger('edge_lab_event_id')->nullable();
            $table->unsignedBigInteger('value_scan_id')->nullable();
            $table->string('sport', 16)->nullable();
            $table->string('market', 16)->nullable();
            $table->string('side', 8)->nullable();
            $table->decimal('line', 8, 2)->nullable();
            $table->string('selection')->nullable();

            $table->decimal('model_prob', 8, 6)->nullable();
            $table->decimal('fair_prob', 8, 6)->nullable();
            $table->decimal('edge', 8, 6)->nullable();
            $table->decimal('edge_at_price', 8, 6)->nullable();
            $table->decimal('ev_per_dollar', 10, 6)->nullable();

            $table->string('best_book', 64)->nullable();
            $table->string('best_book_title')->nullable();
            $table->integer('best_price_american')->nullable();

            $table->decimal('kelly_fraction', 8, 6)->nullable();
            $table->bigInteger('suggested_stake_cents')->nullable();

            // The gate. Only a `proven` verdict may ever become a pick.
            $table->string('market_verdict', 24)->nullable();
            $table->string('recommendation', 8)->nullable();
            $table->string('pass_reason', 32)->nullable();
            $table->text('reason')->nullable();

            $table->timestamp('flagged_at')->nullable();
            $table->json('payload');
            $table->char('digest', 64);
            $table->timestamp('observed_at');

            $table->index(['edge_lab_id', 'id']);
            $table->index(['recommendation', 'market_verdict']);
            $table->index(['sport', 'flagged_at']);
            $table->index('observed_at');
        });

        /*
         * Prices for display, one game at a time, exactly as fetched.
         *
         * Not a feed and never bulk. These rows arrive from /odds/{event}
         * and only for a game somebody is looking at. No route in Vigil
         * returns them for more than one event, and a test asserts it.
         */
        Schema::create('vl_odds_display', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('edge_lab_event_id');
            $table->string('book', 64);
            $table->string('book_title')->nullable();
            $table->string('market', 16)->nullable();
            $table->string('market_key', 48)->nullable();
            $table->string('side', 8)->nullable();
            $table->string('player_name', 120)->nullable();
            $table->decimal('line', 8, 2)->nullable();
            $table->integer('price_american');
            $table->decimal('price_decimal', 12, 4)->nullable();
            $table->timestamp('quoted_at')->nullable();
            $table->char('digest', 64);
            $table->timestamp('observed_at');

            $table->index(['edge_lab_event_id', 'observed_at']);
            $table->index('digest');
        });

        /*
         * Model versions and their verdicts: what decides whether a market
         * may produce a pick at all. Mirrored over time because a verdict
         * changes when a model is retrained, and which verdict was in
         * force when a pick was published is part of the record.
         */
        Schema::create('vl_models', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('edge_lab_id');
            $table->string('sport', 16);
            $table->string('market', 16);
            $table->string('model_type', 24)->nullable();
            $table->unsignedInteger('version')->nullable();
            $table->string('status', 16)->nullable();
            $table->string('verdict', 24)->nullable();
            $table->text('verdict_reason')->nullable();
            $table->boolean('allows_picks')->default(false);
            $table->unsignedInteger('predictions')->nullable();
            $table->decimal('log_loss_improvement', 12, 8)->nullable();
            $table->json('gate')->nullable();
            $table->timestamp('trained_at')->nullable();
            $table->json('payload');
            $table->char('digest', 64);
            $table->timestamp('observed_at');

            $table->index(['edge_lab_id', 'id']);
            $table->index(['sport', 'market', 'id']);
            $table->index('observed_at');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('vl_models');
        Schema::dropIfExists('vl_odds_display');
        Schema::dropIfExists('vl_board_entries');
        Schema::dropIfExists('vl_events');
    }
};
