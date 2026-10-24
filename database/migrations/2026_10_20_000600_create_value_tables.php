<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per scan of the board. Picks are grouped by scan so the
        // picks for today are a single indexed query.
        Schema::create('value_scans', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('scanned_at');
            $table->jsonb('settings');
            $table->bigInteger('bankroll_cents');
            $table->unsignedInteger('candidates')->default(0);
            $table->unsignedInteger('picks')->default(0);
            $table->unsignedBigInteger('job_run_id')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index('scanned_at');
        });

        // APPEND ONLY. Every evaluated candidate is stored, pick or pass, so
        // the board labels everything and nothing silently disappears.
        Schema::create('value_bets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('value_scan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('sport', 16);
            $table->string('market', 16);
            $table->string('side', 8);
            $table->decimal('line', 6, 2)->nullable();
            $table->unsignedBigInteger('prediction_id')->nullable();
            $table->unsignedBigInteger('model_version_id')->nullable();

            $table->decimal('model_prob', 8, 6)->nullable();
            $table->decimal('fair_prob', 8, 6)->nullable();
            $table->string('fair_prob_method', 16)->nullable();
            $table->decimal('edge', 8, 6)->nullable();
            $table->decimal('ev_per_dollar', 8, 6)->nullable();

            $table->foreignId('best_book_id')->nullable()->constrained('books')->nullOnDelete();
            $table->integer('best_price_american')->nullable();
            $table->timestampTz('price_pulled_at')->nullable();
            $table->unsignedSmallInteger('book_count')->default(0);
            // Best price at every book for this side, for line shopping.
            $table->jsonb('line_shopped')->nullable();

            // The two prices that make a pick actionable in plain terms:
            // break_even = the model fair price, min_acceptable = the worst
            // price that still clears the edge threshold (the price cutoff).
            $table->integer('break_even_price_american')->nullable();
            $table->integer('min_acceptable_price_american')->nullable();

            $table->decimal('kelly_fraction', 8, 6)->nullable();
            $table->bigInteger('suggested_stake_cents')->default(0);
            $table->decimal('suggested_stake_units', 8, 2)->default(0);
            $table->bigInteger('bankroll_cents')->nullable();
            $table->decimal('threshold_used', 8, 6)->nullable();

            $table->string('market_verdict', 24)->nullable();
            $table->string('recommendation', 8);
            $table->unsignedSmallInteger('rank')->nullable();
            $table->string('pass_reason', 32)->nullable();
            // One plain sentence for the card, plus the facts behind it.
            $table->string('reason', 255)->nullable();
            $table->jsonb('reason_factors')->nullable();

            $table->timestampTz('flagged_at');
            $table->timestampTz('created_at')->nullable();

            $table->index(['value_scan_id', 'recommendation', 'rank'], 'value_bets_scan_rank_idx');
            $table->index(['event_id', 'market']);
            $table->index('flagged_at');
        });

        Schema::create('arbitrage_opportunities', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('market', 16);
            $table->jsonb('legs');
            $table->decimal('implied_sum', 8, 6);
            $table->decimal('profit_pct', 8, 4);
            $table->jsonb('stake_split')->nullable();
            $table->timestampTz('detected_at');
            $table->timestampTz('created_at')->nullable();

            $table->index(['event_id', 'detected_at']);
        });

        Schema::create('middle_opportunities', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('market', 16);
            $table->jsonb('leg_a');
            $table->jsonb('leg_b');
            $table->decimal('middle_low', 6, 2);
            $table->decimal('middle_high', 6, 2);
            $table->decimal('width', 6, 2);
            $table->decimal('net_cost_pct', 8, 4)->nullable();
            $table->decimal('model_hit_prob', 8, 6)->nullable();
            $table->timestampTz('detected_at');
            $table->timestampTz('created_at')->nullable();

            $table->index(['event_id', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('middle_opportunities');
        Schema::dropIfExists('arbitrage_opportunities');
        Schema::dropIfExists('value_bets');
        Schema::dropIfExists('value_scans');
    }
};
