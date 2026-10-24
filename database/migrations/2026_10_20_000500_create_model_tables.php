<?php

declare(strict_types=1);

use App\Enums\ModelStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_versions', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 16);
            $table->string('market', 16);
            $table->string('model_type', 24);
            $table->unsignedInteger('version');
            $table->string('label')->nullable();
            $table->jsonb('params');
            $table->jsonb('calibration')->nullable();
            $table->unsignedSmallInteger('feature_schema_version')->nullable();
            $table->timestampTz('trained_at');
            $table->timestampTz('trained_through_at');
            $table->unsignedInteger('training_rows')->default(0);
            $table->jsonb('holdout_metrics')->nullable();
            $table->string('status', 16)->default(ModelStatus::Candidate->value);
            $table->unsignedBigInteger('backtest_run_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();

            $table->unique(['sport', 'market', 'model_type', 'version'], 'model_versions_unique');
            $table->index(['sport', 'market', 'status']);
        });

        // APPEND ONLY and timestamped before kickoff. This table is the
        // honest track record: a prediction row is never altered.
        Schema::create('predictions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('model_version_id')->constrained('model_versions')->restrictOnDelete();
            $table->string('market', 16);
            $table->string('side', 8);
            $table->decimal('line', 6, 2)->nullable();
            $table->decimal('prob', 8, 6);
            $table->integer('fair_price_american');
            $table->decimal('projected_home_score', 6, 2)->nullable();
            $table->decimal('projected_away_score', 6, 2)->nullable();
            $table->decimal('projected_margin', 6, 2)->nullable();
            $table->decimal('projected_total', 6, 2)->nullable();
            $table->decimal('sigma', 8, 4)->nullable();
            $table->jsonb('components')->nullable();
            $table->unsignedBigInteger('feature_set_id')->nullable();
            $table->jsonb('feature_contributions')->nullable();
            $table->timestampTz('predicted_at');
            $table->timestampTz('event_starts_at');
            $table->timestampTz('created_at')->nullable();

            $table->index(['event_id', 'market', 'predicted_at'], 'predictions_event_market_idx');
            $table->index('model_version_id');
        });

        Schema::create('backtest_runs', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 16);
            $table->string('market', 16);
            $table->string('model_type', 24);
            $table->jsonb('config');
            $table->unsignedSmallInteger('train_window_days')->nullable();
            $table->unsignedSmallInteger('step_days')->nullable();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('status', 16);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('n_predictions')->default(0);
            $table->jsonb('metrics')->nullable();
            $table->jsonb('market_metrics')->nullable();
            $table->decimal('delta_log_loss', 10, 6)->nullable();
            $table->decimal('avg_clv_pct', 8, 4)->nullable();
            $table->string('verdict', 24)->nullable();
            $table->unsignedBigInteger('job_run_id')->nullable();
            $table->unsignedBigInteger('model_version_id')->nullable();
            $table->timestampsTz();

            $table->index(['sport', 'market', 'created_at']);
        });

        Schema::create('backtest_predictions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('backtest_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('market', 16);
            $table->string('side', 8);
            $table->decimal('line', 6, 2)->nullable();
            $table->decimal('prob', 8, 6);
            $table->decimal('market_fair_prob', 8, 6)->nullable();
            $table->integer('price_at_predict_american')->nullable();
            $table->integer('closing_price_american')->nullable();
            $table->string('result', 8)->nullable();
            $table->bigInteger('pnl_flat_cents')->default(0);
            $table->decimal('clv_prob_delta', 8, 6)->nullable();
            $table->timestampTz('predicted_at');
            $table->timestampTz('as_of');

            $table->index(['backtest_run_id', 'event_id']);
        });

        Schema::create('calibration_bins', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 24);
            $table->unsignedBigInteger('owner_id');
            $table->unsignedSmallInteger('bin_index');
            $table->decimal('p_low', 8, 6);
            $table->decimal('p_high', 8, 6);
            $table->unsignedInteger('n');
            $table->decimal('mean_predicted', 8, 6);
            $table->decimal('observed_rate', 8, 6);
            $table->timestampTz('created_at')->nullable();

            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calibration_bins');
        Schema::dropIfExists('backtest_predictions');
        Schema::dropIfExists('backtest_runs');
        Schema::dropIfExists('predictions');
        Schema::dropIfExists('model_versions');
    }
};
