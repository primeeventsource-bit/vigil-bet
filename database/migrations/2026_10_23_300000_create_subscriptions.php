<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Derived subscription state. One row per user.
 *
 * Derived is the important word. From step 6 this table is rebuilt from
 * `billing_events`, which is append-only and is the actual record - a
 * webhook arriving twice, out of order, or after a manual change must
 * never leave the two disagreeing, and the only way to guarantee that is
 * for one of them to be a projection of the other.
 *
 * Until billing exists it is written directly, by an operator or a test,
 * and `source` says which. A row nobody can account for is worse than no
 * row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // free | daily | pro. Validated against config/plans.php.
            $table->string('tier', 16);
            // trialing | active | past_due | cancelled
            $table->string('status', 16)->default('active');

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_end')->nullable();

            /*
             * Cancelling never takes access away mid-period. §4 is
             * explicit about it and so is the terms page, so the flag is
             * separate from the status: a cancelled-at-period-end
             * subscription is still active until that date arrives.
             */
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();

            // billing | operator | signup. How this row came to exist.
            $table->string('source', 16)->default('signup');

            $table->timestamps();

            $table->index(['tier', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
