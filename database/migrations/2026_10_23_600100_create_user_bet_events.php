<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * APPEND ONLY. The event log a customer's bet is derived from.
 *
 * §11 asks for this shape, and the reason is the same one that applies to
 * billing: a track record is a claim about the past, and a claim about
 * the past that lives in a mutable row is not evidence of anything. If a
 * customer asks why their ROI changed, or we need to show that a bet was
 * graded from a result rather than edited by hand, the answer has to be
 * readable off a log.
 *
 * It also means a settlement bug can be fixed and replayed rather than
 * leaving every affected row permanently wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_bet_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_bet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('type');
            $table->json('payload')->nullable();

            /*
             * Who caused it. Null for an automatic grading, which is the
             * common case - and the distinction matters, because "the
             * engine settled this from the final score" and "somebody
             * marked this won" are different claims.
             */
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();

            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at');

            $table->index(['user_bet_id', 'occurred_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION vigil_user_bet_events_append_only()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'user_bet_events is append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER user_bet_events_append_only
                BEFORE UPDATE OR DELETE ON user_bet_events
                FOR EACH ROW EXECUTE FUNCTION vigil_user_bet_events_append_only();
            SQL);
        }
    }
};
