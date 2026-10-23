<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per call to the model, including the ones that failed
 * validation and were thrown away.
 *
 * Especially those. A Read that was regenerated cost twice and shipped
 * once, and a month where that happens often is a prompt problem showing
 * up as a bill. Counting only the calls that produced something visible
 * would hide exactly the failure worth finding.
 *
 * Cost is computed and stored at the time of the call rather than derived
 * later from a price table, because the price table changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('purpose', 32);
            $table->string('model', 48);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            // Tenths of a cent, as an integer. Money is never a float, and
            // a single Read costs well under a cent.
            $table->unsignedBigInteger('cost_millicents')->default(0);
            $table->boolean('accepted')->default(true);
            $table->string('outcome', 32)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at');

            $table->index(['created_at']);
            $table->index(['purpose', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
