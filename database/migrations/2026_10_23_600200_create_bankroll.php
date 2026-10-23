<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The bankroll: a ledger and a set of limits.
 *
 * §8 asks for Kelly sizing, stake caps, and daily and weekly loss limits
 * "with no override". That last phrase is the whole design. A loss limit
 * a customer can lift in the moment is not a loss limit; it is a prompt
 * that appears at the worst possible time and asks somebody who is losing
 * whether they would like to keep going.
 *
 * So the limits are stored with a cooling-off date, raising one takes
 * effect tomorrow, and lowering one takes effect at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bankroll_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Integer cents throughout.
            $table->unsignedBigInteger('starting_cents')->default(0);

            /*
             * Kelly, scaled. Full Kelly is correct only if the model's
             * probability is exactly right, and it never is, so the
             * product offers a fraction of it and defaults to a quarter.
             */
            $table->decimal('kelly_scale', 4, 3)->default(0.250);

            // A ceiling that applies whatever Kelly says.
            $table->decimal('max_stake_pct', 5, 2)->default(2.00);

            $table->unsignedBigInteger('daily_loss_limit_cents')->nullable();
            $table->unsignedBigInteger('weekly_loss_limit_cents')->nullable();

            /*
             * A raise takes effect from this date. Set to tomorrow when a
             * customer loosens a limit, so the decision is made by the
             * person they were yesterday rather than the one in front of
             * a losing afternoon.
             */
            $table->date('pending_limits_effective_on')->nullable();
            $table->json('pending_limits')->nullable();

            $table->timestamps();
        });

        Schema::create('bankroll_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * Signed cents. A stake is negative, a return is positive, and
             * the balance is their sum - so the ledger adds up by
             * construction rather than by a routine that has to be run.
             */
            $table->string('kind');
            $table->bigInteger('amount_cents');

            $table->foreignId('user_bet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();

            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at');

            $table->index(['user_id', 'occurred_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION vigil_bankroll_entries_append_only()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'bankroll_entries is append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER bankroll_entries_append_only
                BEFORE UPDATE OR DELETE ON bankroll_entries
                FOR EACH ROW EXECUTE FUNCTION vigil_bankroll_entries_append_only();
            SQL);
        }
    }
};
