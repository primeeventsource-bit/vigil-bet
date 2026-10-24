<?php

declare(strict_types=1);

use App\Enums\BetStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // APPEND ONLY and versioned. The app always reads the latest row
        // whose effective_from has passed, so old stakes stay explainable.
        Schema::create('bankroll_settings', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('starting_bankroll_cents');
            $table->bigInteger('unit_size_cents');
            $table->decimal('kelly_fraction', 4, 3)->default(0.250);
            $table->decimal('max_stake_pct', 5, 2)->default(2.00);
            $table->decimal('max_daily_exposure_pct', 5, 2)->default(6.00);
            $table->bigInteger('daily_loss_limit_cents');
            $table->bigInteger('weekly_loss_limit_cents');
            $table->decimal('edge_threshold', 8, 6)->default(0.030000);
            $table->unsignedSmallInteger('min_book_count')->default(3);
            $table->boolean('require_proven_market')->default(true);
            $table->timestampTz('effective_from');
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index('effective_from');
        });

        // Read model projected from bet_events. Never written to directly
        // outside the projector.
        Schema::create('bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_custom')->default(false);
            $table->boolean('is_paper')->default(false);
            $table->string('custom_description')->nullable();
            $table->string('sport', 16)->nullable();
            $table->string('market', 16)->nullable();
            $table->string('side', 8)->nullable();
            $table->decimal('line', 6, 2)->nullable();
            $table->foreignId('book_id')->nullable()->constrained('books')->nullOnDelete();
            $table->integer('price_american');
            $table->decimal('price_decimal', 10, 4);
            $table->bigInteger('stake_cents');
            $table->timestampTz('placed_at');

            $table->unsignedBigInteger('value_bet_id')->nullable();
            $table->unsignedBigInteger('prediction_id')->nullable();
            $table->unsignedBigInteger('model_version_id')->nullable();
            $table->decimal('model_prob_at_placement', 8, 6)->nullable();
            $table->decimal('fair_prob_at_placement', 8, 6)->nullable();
            $table->decimal('edge_at_placement', 8, 6)->nullable();

            $table->integer('closing_price_american')->nullable();
            $table->decimal('clv_prob_delta', 8, 6)->nullable();
            $table->bigInteger('clv_cents')->nullable();

            $table->string('status', 16)->default(BetStatus::Pending->value);
            $table->timestampTz('settled_at')->nullable();
            $table->bigInteger('payout_cents')->default(0);
            $table->bigInteger('pnl_cents')->default(0);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index('placed_at');
            $table->index(['status', 'placed_at']);
            $table->index(['event_id', 'market']);
        });

        // APPEND ONLY. The ledger of truth for every bet: placed, graded,
        // voided, corrected. bets is derived from this.
        Schema::create('bet_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('bet_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('type', 16);
            $table->jsonb('payload')->nullable();
            $table->bigInteger('stake_delta_cents')->default(0);
            $table->bigInteger('pnl_delta_cents')->default(0);
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->nullable();

            $table->unique(['bet_id', 'sequence']);
            $table->index('occurred_at');
        });

        // APPEND ONLY running bankroll.
        Schema::create('bankroll_ledger', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('entry_type', 16);
            $table->bigInteger('amount_cents');
            $table->bigInteger('balance_after_cents');
            $table->unsignedBigInteger('bet_event_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index('occurred_at');
        });

        // Self-imposed limits. Once locked_at is set it is never cleared
        // inside the period; only a new period unlocks.
        Schema::create('loss_limit_periods', function (Blueprint $table) {
            $table->id();
            $table->string('period_type', 8);
            $table->date('period_start');
            $table->date('period_end');
            $table->bigInteger('limit_cents');
            $table->bigInteger('realized_pnl_cents')->default(0);
            $table->bigInteger('staked_cents')->default(0);
            $table->bigInteger('exposure_cents')->default(0);
            $table->timestampTz('locked_at')->nullable();
            $table->string('lock_reason', 64)->nullable();
            $table->timestampsTz();

            $table->unique(['period_type', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loss_limit_periods');
        Schema::dropIfExists('bankroll_ledger');
        Schema::dropIfExists('bet_events');
        Schema::dropIfExists('bets');
        Schema::dropIfExists('bankroll_settings');
    }
};
