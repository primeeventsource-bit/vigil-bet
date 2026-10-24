<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->jsonb('value')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });

        // Every scheduled or queued job writes exactly one row here, success
        // or failure. No silent failures.
        Schema::create('job_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('job_key', 96);
            $table->string('job_class')->nullable();
            $table->string('sport', 16)->nullable();
            $table->string('status', 16);
            $table->string('triggered_by', 16)->default('schedule');
            $table->string('queue', 32)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->unsignedInteger('records_in')->default(0);
            $table->unsignedInteger('records_out')->default(0);
            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['job_key', 'started_at']);
            $table->index(['status', 'started_at']);
        });

        // One row per outbound API call. This is how quota is tracked and how
        // a provider outage becomes visible instead of silent.
        Schema::create('api_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('provider', 32);
            $table->string('endpoint', 191);
            $table->string('method', 8)->default('GET');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('quota_used')->nullable();
            $table->unsignedInteger('quota_remaining')->nullable();
            $table->unsignedBigInteger('response_bytes')->nullable();
            $table->unsignedInteger('items_written')->default(0);
            $table->boolean('cache_hit')->default(false);
            $table->unsignedBigInteger('job_run_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('requested_at');

            $table->index(['provider', 'requested_at']);
            $table->index('job_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_requests');
        Schema::dropIfExists('job_runs');
        Schema::dropIfExists('app_settings');
    }
};
