<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The processor's handles, and the billing interval, on the derived
 * subscription row.
 *
 * `subscriptions` is a projection of billing_events and may be rebuilt
 * from it at any time, so nothing here is a source of truth - it is a
 * cache of the answer, kept because every page load asks the question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('interval')->nullable()->after('tier');

            $table->string('gateway')->nullable()->after('source');
            $table->string('gateway_customer_id')->nullable()->after('gateway');
            $table->string('gateway_subscription_id')->nullable()->after('gateway_customer_id');

            /*
             * The last event folded in. Makes a stale projection
             * visible: a subscription whose last event is older than the
             * newest event for that user has not been rebuilt, and
             * that is worth being able to see rather than infer.
             */
            $table->unsignedBigInteger('last_billing_event_id')->nullable()->after('gateway_subscription_id');

            $table->index('gateway_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['gateway_subscription_id']);
            $table->dropColumn([
                'interval',
                'gateway',
                'gateway_customer_id',
                'gateway_subscription_id',
                'last_billing_event_id',
            ]);
        });
    }
};
