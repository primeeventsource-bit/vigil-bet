<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far ahead of kickoff a forecast was made.
 *
 * This corrects a real flaw in the first version of the weather adapter.
 * It stored only `fetched_at`, which answers "when did we ask" - fine for a
 * live pull, and meaningless for a backfill, where we ask today about a
 * game two seasons ago. Worse, it left live forecasts taken six days out
 * and six hours out indistinguishable except by arithmetic, so a feature
 * reading "the forecast" would silently mix lead times and learn from a
 * blend of confident and speculative numbers.
 *
 * `lead_days` makes the run explicit. A feature can then ask for the
 * forecast as it stood a fixed number of days before kickoff and get the
 * same thing in training as in use, which is the only way the two can be
 * compared at all.
 *
 * Both columns are nullable and nothing is backfilled, which is not
 * laziness: `weather_readings` carries an append-only trigger in Postgres,
 * so a migration that tried to amend the rows already written would be
 * refused by the database - correctly. Those rows are live forecasts and
 * their lead is recoverable from the two timestamps they already have, so
 * WeatherReading::leadDays() derives it rather than rewriting history to
 * suit a newer column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weather_readings', function (Blueprint $table) {
            $table->unsignedSmallInteger('lead_days')->nullable()->after('is_forecast');

            // Which model run the numbers came from, in the source's own
            // words. A provenance claim is worth nothing if the row cannot
            // say where it came from.
            $table->string('run', 32)->nullable()->after('lead_days');

            $table->index(['event_id', 'lead_days'], 'weather_event_lead_idx');
        });
    }

    public function down(): void
    {
        Schema::table('weather_readings', function (Blueprint $table) {
            $table->dropIndex('weather_event_lead_idx');
            $table->dropColumn(['lead_days', 'run']);
        });
    }
};
