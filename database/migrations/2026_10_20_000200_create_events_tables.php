<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 16);
            $table->foreignId('season_id')->nullable()->constrained('seasons')->nullOnDelete();
            $table->unsignedSmallInteger('season')->nullable();
            $table->string('season_type', 8)->nullable();
            $table->unsignedSmallInteger('week')->nullable();
            $table->foreignId('home_team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('away_team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->boolean('neutral_site')->default(false);
            $table->timestampTz('starts_at');
            $table->string('status', 16)->default(EventStatus::Scheduled->value);
            $table->integer('final_home_score')->nullable();
            $table->integer('final_away_score')->nullable();
            $table->jsonb('period_scores')->nullable();
            $table->boolean('went_overtime')->default(false);
            $table->timestampTz('completed_at')->nullable();
            $table->string('odds_api_event_id', 64)->nullable();
            $table->timestampTz('last_result_sync_at')->nullable();
            $table->timestampsTz();

            $table->unique(['sport', 'starts_at', 'home_team_id', 'away_team_id'], 'events_natural_unique');
            $table->index(['sport', 'starts_at']);
            $table->index(['status', 'starts_at']);
            $table->index('odds_api_event_id');
        });

        Schema::create('event_external_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32);
            $table->string('external_id', 64);
            $table->timestampsTz();

            $table->unique(['source', 'external_id']);
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_external_ids');
        Schema::dropIfExists('events');
    }
};
