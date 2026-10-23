<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * APPEND ONLY.
 *
 * Subscription state is derived from this table, not stored beside it.
 * §5 asks for that, and the reason is that billing disputes are
 * arguments about history: "we cancelled on the 3rd" is answerable from
 * an event log and unanswerable from a row that has been updated in
 * place eleven times.
 *
 * `gateway_event_id` is unique, which is the whole of webhook
 * idempotency. A processor that delivers the same event twice - and they
 * all do - cannot charge a customer twice or downgrade them twice,
 * because the second insert fails at the database rather than in a
 * handler somebody might refactor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_events', function (Blueprint $table): void {
            $table->id();

            // Which processor said it. Kept so the log survives a change
            // of processor and stays readable afterwards.
            $table->string('driver');

            /*
             * The processor's own id for this event. Unique, and
             * nullable because an event we raise ourselves - a plan
             * change made in Account, a dunning downgrade - has no
             * processor id and must not collide with another local one.
             */
            $table->string('gateway_event_id')->nullable()->unique();

            $table->string('type');

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // The processor's handles. Not credentials, and never a card
            // number: §5 forbids card data touching these servers.
            $table->string('gateway_customer_id')->nullable();
            $table->string('gateway_subscription_id')->nullable();

            $table->string('tier')->nullable();
            $table->string('interval')->nullable();

            // Integer cents. Nullable because most events are not money.
            $table->integer('amount_cents')->nullable();
            $table->string('currency', 3)->nullable();

            /*
             * When the processor says the paid period runs to. This is
             * the field access is decided by, so it is a column rather
             * than a key in the payload.
             */
            $table->timestamp('period_end')->nullable();

            // The whole payload as received, for the dispute nobody has
            // had yet. Signature-verified before it is written.
            $table->json('payload')->nullable();

            // Set when we raise the event rather than the processor.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();

            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at');

            $table->index(['user_id', 'occurred_at']);
            $table->index(['driver', 'type']);
        });

        /*
         * Enforced in the database as well as the model. An append-only
         * table that is only append-only in PHP is append-only until
         * somebody writes a migration, a console command or a tinker
         * one-liner - and this is the table that answers chargebacks.
         */
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION vigil_billing_events_append_only()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'billing_events is append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER billing_events_append_only
                BEFORE UPDATE OR DELETE ON billing_events
                FOR EACH ROW EXECUTE FUNCTION vigil_billing_events_append_only();
            SQL);
        }
    }
};
