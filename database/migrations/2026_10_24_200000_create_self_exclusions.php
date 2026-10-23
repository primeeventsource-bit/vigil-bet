<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * APPEND ONLY, and it cannot be undone early.
 *
 * §9: "Self-exclusion: user can lock themselves out for 7, 30 or 90 days;
 * cannot be undone early."
 *
 * That second clause is the entire feature. A self-exclusion a customer
 * can lift is not a self-exclusion - it is a cooling-off button, and the
 * person it exists to protect is precisely the person who will be looking
 * for the way out at two in the morning. So:
 *
 *  - the row is append-only, in the model and in Postgres
 *  - there is no column for cancelling, because a nullable
 *    `cancelled_at` is an invitation to write an endpoint that sets it
 *  - `ends_at` is computed on insert from a fixed set of durations and
 *    cannot be edited afterwards
 *  - a CHECK constraint rejects an exclusion that ends before it starts
 *
 * The only way out is time passing. There is deliberately no admin
 * action to shorten one either - an operator who can be talked into
 * lifting it is the same hole with an extra step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('self_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 7, 30 or 90. Stored so the record says what was chosen
            // rather than only what was computed from it.
            $table->unsignedSmallInteger('days');

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->index();

            /*
             * What the customer was shown when they chose it. Kept
             * because a self-exclusion is the one decision in this
             * product somebody may later say they did not understand,
             * and the answer should not depend on what the page happens
             * to say today.
             */
            $table->string('acknowledged');

            $table->timestamp('created_at');

            $table->index(['user_id', 'ends_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE self_exclusions ADD CONSTRAINT exclusion_ends_after_it_starts '
                .'CHECK (ends_at > starts_at)'
            );

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION vigil_self_exclusions_append_only()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'self_exclusions is append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER self_exclusions_append_only
                BEFORE UPDATE OR DELETE ON self_exclusions
                FOR EACH ROW EXECUTE FUNCTION vigil_self_exclusions_append_only();
            SQL);
        }

        if (DB::getDriverName() === 'sqlite') {
            // SQLite cannot add a CHECK to an existing table, so the
            // same guarantee goes on as a trigger - in the database
            // either way, which is the point.
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER self_exclusions_end_after_start
                BEFORE INSERT ON self_exclusions
                FOR EACH ROW WHEN NEW.ends_at <= NEW.starts_at
                BEGIN
                    SELECT RAISE(ABORT, 'self_exclusions: ends_at must be after starts_at');
                END;
            SQL);
        }
    }
};
