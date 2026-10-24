<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each call actually cost, rather than how many calls were made.
 *
 * Until now the budget was counted in requests, which was correct while
 * every request cost one credit. It stops being correct the moment props
 * and history are pulled: one props call costs a credit per market per
 * region returned, and one historical call costs ten. A month of those
 * counted as "one each" reads as 3% of the plan while spending 30% of it.
 *
 * The Odds API already reports the price of every call in `x-requests-last`
 * and always has. This stores it, so the number on the diagnostic screen is
 * credits and not a proxy for them.
 *
 * Nullable, because a call made before this column existed has no honest
 * answer and 1 is a guess. The readers treat null as "unknown, assume one",
 * which is what was previously assumed for every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_requests', function (Blueprint $table) {
            $table->unsignedInteger('credits_cost')->nullable()->after('quota_remaining');
        });
    }

    public function down(): void
    {
        Schema::table('api_requests', function (Blueprint $table) {
            $table->dropColumn('credits_cost');
        });
    }
};
