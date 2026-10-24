<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per call to the internal API, in the shape of job_runs: what was
 * asked for, what came back, how long it took, and what went wrong.
 *
 * Both halves matter. The successes are how a sync problem on the far side
 * gets diagnosed from this side - a client that stopped asking looks
 * exactly like a client that is up to date until you can see it stopped
 * asking. The rejections are the security log: a wrong token or an address
 * off the allowlist is recorded with the reason, which is the only way a
 * probe is distinguishable from a misconfiguration.
 *
 * Append-only, like every other history in this app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('route', 96)->nullable();
            $table->string('method', 8);
            $table->string('path', 191);
            $table->string('ip', 45)->nullable();
            $table->unsignedSmallInteger('http_status');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('records_returned')->default(0);
            // The cursor asked for and the one handed back, so a client
            // stuck in a loop over the same page is visible from here.
            $table->string('since', 64)->nullable();
            $table->string('next_since', 64)->nullable();
            $table->boolean('authenticated')->default(false);
            // Null on success. Named rather than free text, so the rows can
            // be counted: bad_token, ip_not_allowed, disabled, rate_limited.
            $table->string('rejected_reason', 32)->nullable();
            $table->timestampTz('requested_at');

            $table->index(['requested_at']);
            $table->index(['rejected_reason', 'requested_at']);
            $table->index(['route', 'requested_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(
                'CREATE TRIGGER internal_requests_append_only
                 BEFORE UPDATE OR DELETE ON internal_requests
                 FOR EACH ROW EXECUTE FUNCTION edge_deny_mutation();'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS internal_requests_append_only ON internal_requests;');
        }

        Schema::dropIfExists('internal_requests');
    }
};
