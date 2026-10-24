<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_game_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opponent_team_id')->constrained('teams')->cascadeOnDelete();
            $table->boolean('is_home');
            $table->integer('points')->nullable();
            $table->integer('points_allowed')->nullable();
            $table->jsonb('period_scores')->nullable();
            $table->decimal('possessions', 8, 2)->nullable();
            $table->decimal('pace', 8, 2)->nullable();
            $table->jsonb('stats')->nullable();
            $table->string('source', 32);
            $table->timestampTz('as_of');
            $table->timestampsTz();

            $table->unique(['event_id', 'team_id']);
            $table->index(['team_id', 'as_of']);
        });

        // APPEND ONLY. Each report is a point-in-time observation; the model
        // may only read reports timestamped before kickoff.
        Schema::create('injury_reports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('sport', 16);
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('player_external_id', 64)->nullable();
            $table->string('player_name');
            $table->string('position', 16)->nullable();
            $table->string('status', 16);
            $table->text('detail')->nullable();
            $table->string('source', 32);
            $table->timestampTz('reported_at');
            $table->timestampTz('created_at')->nullable();

            $table->index(['team_id', 'reported_at']);
            $table->index(['event_id', 'reported_at']);
        });

        // APPEND ONLY. Probable/confirmed starting pitcher (MLB) or goalie (NHL).
        Schema::create('probable_starters', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->string('player_external_id', 64)->nullable();
            $table->string('player_name');
            $table->string('handedness', 2)->nullable();
            $table->boolean('is_confirmed')->default(false);
            $table->string('source', 32);
            $table->timestampTz('reported_at');
            $table->timestampTz('created_at')->nullable();

            $table->index(['event_id', 'team_id', 'reported_at'], 'probable_starters_event_idx');
        });

        // APPEND ONLY. Forecasts before the game, observations after.
        Schema::create('weather_readings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_forecast')->default(true);
            $table->timestampTz('valid_at');
            $table->decimal('temp_f', 5, 1)->nullable();
            $table->decimal('wind_mph', 5, 1)->nullable();
            $table->unsignedSmallInteger('wind_deg')->nullable();
            $table->decimal('gust_mph', 5, 1)->nullable();
            $table->decimal('precip_prob', 5, 2)->nullable();
            $table->decimal('precip_in', 5, 2)->nullable();
            $table->decimal('humidity', 5, 2)->nullable();
            $table->decimal('pressure_mb', 7, 2)->nullable();
            $table->string('conditions', 64)->nullable();
            $table->string('source', 32);
            $table->timestampTz('fetched_at');

            $table->index(['event_id', 'fetched_at']);
        });

        // APPEND ONLY. max_source_at is the leakage guard: the newest source
        // timestamp that fed this feature vector. It must precede kickoff.
        Schema::create('feature_sets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('schema_version');
            $table->jsonb('payload');
            $table->timestampTz('as_of');
            $table->timestampTz('max_source_at');
            $table->timestampTz('event_starts_at');
            $table->timestampTz('built_at');
            $table->timestampTz('created_at')->nullable();

            $table->index(['event_id', 'as_of']);
            $table->index('schema_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_sets');
        Schema::dropIfExists('weather_readings');
        Schema::dropIfExists('probable_starters');
        Schema::dropIfExists('injury_reports');
        Schema::dropIfExists('team_game_stats');
    }
};
