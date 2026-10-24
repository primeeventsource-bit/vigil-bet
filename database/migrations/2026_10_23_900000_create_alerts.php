<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Alerts, in three tables that do three different jobs.
 *
 *  - `alerts` is APPEND ONLY: one thing that happened which is worth
 *    telling somebody about. Raised once per subject, so a pick that is
 *    re-scanned does not generate a second alert.
 *  - `alert_deliveries` is APPEND ONLY: one attempt to tell one person on
 *    one channel. Unique on (alert, user, channel), which is the whole of
 *    "nobody gets told twice" - enforced by the database rather than by a
 *    handler somebody might refactor.
 *  - `alert_preferences` is ordinary mutable state: what a customer asked
 *    for. It changes, and its history is not evidence of anything.
 *
 * The separation matters because the failure here is not a missing alert.
 * It is a customer getting the same push eleven times at two in the
 * morning, which is the kind of thing that gets an application uninstalled
 * and a sender blocked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table): void {
            $table->id();

            $table->string('type')->index();

            // What it is about: a published pick, a game whose line moved.
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            // Carried so the dispatcher can match a customer's filters
            // without loading the subject.
            $table->string('sport')->nullable()->index();
            $table->string('market')->nullable();

            // Everything the message needs, resolved at raise time. A
            // notification built from a live lookup could describe a
            // price that has since moved.
            $table->json('payload');

            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at');

            /*
             * One alert per subject per type, for ever. The raiser runs
             * hourly and a pick that is still on the board must not
             * produce a second alert every time it is seen.
             */
            $table->unique(['type', 'subject_type', 'subject_id']);
        });

        Schema::create('alert_deliveries', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('channel');

            // sent / failed / skipped. A skip carries its reason, so
            // "why did I not get this" is answerable.
            $table->string('status');
            $table->string('reason')->nullable();

            $table->timestamp('delivered_at');
            $table->timestamp('created_at');

            // Nobody gets told twice. In the database, not in a handler.
            $table->unique(['alert_id', 'user_id', 'channel']);
            $table->index(['user_id', 'delivered_at']);
        });

        Schema::create('alert_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            /*
             * Both channels default to off. An account that started
             * emailing somebody because they signed up would be sending
             * unsolicited mail, whatever the footer said.
             */
            $table->boolean('email_enabled')->default(false);
            $table->boolean('push_enabled')->default(false);

            $table->boolean('on_new_pick')->default(true);
            $table->boolean('on_line_move')->default(false);

            // Empty means every sport. A list means only those.
            $table->json('sports')->nullable();

            /*
             * How far a price has to move before it is worth a
             * notification. Default deliberately high: a product that
             * tells you about every two-cent wobble is a product you
             * turn off.
             */
            $table->unsignedInteger('line_move_threshold')->default(15);

            /*
             * Local hours during which nothing is sent. §9 is about
             * responsible gambling and a 3am alert about a line move is
             * the opposite of it.
             */
            $table->unsignedTinyInteger('quiet_from_hour')->nullable();
            $table->unsignedTinyInteger('quiet_to_hour')->nullable();
            $table->string('timezone')->default('UTC');

            $table->timestamps();
        });

        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The browser's own endpoint. Unique: the same browser
            // re-subscribing must replace rather than accumulate.
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();

            // The keys the browser generated. Not credentials of ours,
            // and useless to anybody without the VAPID private key.
            $table->string('p256dh');
            $table->string('auth');

            $table->string('user_agent')->nullable();

            /*
             * A browser that has been uninstalled returns 404 or 410 for
             * ever. Counted so a dead subscription can be dropped rather
             * than retried on every alert until the end of time.
             */
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();
        });

        Schema::create('event_watches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('edge_lab_event_id');
            $table->timestamp('created_at');

            $table->unique(['user_id', 'edge_lab_event_id']);
            $table->index('edge_lab_event_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (['alerts', 'alert_deliveries'] as $appendOnly) {
                DB::unprepared(<<<SQL
                    CREATE OR REPLACE FUNCTION vigil_{$appendOnly}_append_only()
                    RETURNS trigger AS \$\$
                    BEGIN
                        RAISE EXCEPTION '{$appendOnly} is append-only';
                    END;
                    \$\$ LANGUAGE plpgsql;

                    CREATE TRIGGER {$appendOnly}_append_only
                    BEFORE UPDATE OR DELETE ON {$appendOnly}
                    FOR EACH ROW EXECUTE FUNCTION vigil_{$appendOnly}_append_only();
                SQL);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_deliveries');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('alert_preferences');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('event_watches');
    }
};
