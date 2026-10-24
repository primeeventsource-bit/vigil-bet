<?php

declare(strict_types=1);

use App\Enums\SeasonType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('city')->nullable();
            $table->string('region', 64)->nullable();
            $table->string('country', 2)->nullable();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lon', 9, 6)->nullable();
            $table->string('tz', 64)->nullable();
            $table->boolean('is_dome')->default(false);
            $table->string('surface', 32)->nullable();
            $table->integer('altitude_m')->nullable();
            $table->timestampsTz();

            $table->index('name');
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 16);
            $table->string('name');
            $table->string('display_name');
            $table->string('abbreviation', 12)->nullable();
            $table->string('slug', 64);
            $table->string('conference', 64)->nullable();
            $table->string('division', 64)->nullable();
            $table->foreignId('primary_venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->string('timezone', 64)->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->unique(['sport', 'slug']);
            $table->index(['sport', 'active']);
        });

        Schema::create('team_external_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32);
            $table->string('external_id', 64);
            $table->timestampsTz();

            $table->unique(['source', 'external_id']);
            $table->index('team_id');
        });

        // Normalized lookup table. A name we cannot resolve is logged in
        // unmatched_names - never guessed at.
        Schema::create('team_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 16);
            $table->string('source', 32)->nullable();
            $table->string('alias_raw');
            $table->string('alias_norm', 191);
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('origin', 16)->default('seed');
            $table->timestampsTz();

            $table->unique(['sport', 'source', 'alias_norm'], 'team_aliases_lookup_unique');
            $table->index('alias_norm');
        });

        Schema::create('unmatched_names', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 16);
            $table->string('source', 32);
            $table->string('raw_name');
            $table->string('norm_name', 191);
            $table->jsonb('context')->nullable();
            $table->unsignedInteger('hits')->default(1);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');

            $table->unique(['sport', 'source', 'norm_name'], 'unmatched_names_unique');
        });

        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('key', 48)->unique();
            $table->string('title');
            $table->string('region', 16)->default('us');
            $table->boolean('is_tracked')->default(true);
            $table->boolean('is_exchange')->default(false);
            $table->unsignedSmallInteger('display_order')->default(100);
            $table->timestampsTz();
        });

        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 16);
            $table->unsignedSmallInteger('year');
            $table->string('season_type', 8)->default(SeasonType::Regular->value);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('weeks')->nullable();
            $table->timestampsTz();

            $table->unique(['sport', 'year', 'season_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasons');
        Schema::dropIfExists('books');
        Schema::dropIfExists('unmatched_names');
        Schema::dropIfExists('team_aliases');
        Schema::dropIfExists('team_external_ids');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('venues');
    }
};
