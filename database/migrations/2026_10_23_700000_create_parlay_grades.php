<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * APPEND ONLY. One slip, as Edge Lab judged it.
 *
 * Stored rather than computed on the fly for three reasons, in order of
 * how much they matter:
 *
 *  - It is the subject a Vigil Read attaches to. §6 asks for a Read on a
 *    parlay grade, and a Read needs something append-only to belong to.
 *  - §4 limits the free plan to three grades a day, and a limit has to be
 *    counted against something that cannot be cleared.
 *  - A customer who was told a slip was poor value should be able to see
 *    what they were told, in the words they were told it, afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parlay_grades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * The legs as submitted, and the verdict as returned. Both
             * verbatim: a grade reproduced from re-derived legs is not
             * the grade the customer saw.
             */
            $table->json('legs');
            $table->json('result')->nullable();

            // Pulled out of the result for the columns a page sorts and
            // filters on. Everything else stays in the JSON.
            $table->integer('leg_count');
            $table->decimal('win_probability', 8, 6)->nullable();
            $table->integer('fair_price_american')->nullable();
            $table->integer('offered_price_american')->nullable();
            $table->decimal('book_hold', 8, 5)->nullable();
            $table->decimal('shaded_edge', 8, 5)->nullable();
            $table->string('verdict')->nullable();

            /*
             * Set when Edge Lab could not be reached. A grade that
             * silently became an empty result would look like a slip
             * with no value rather than a question nobody answered.
             */
            $table->string('unavailable_reason')->nullable();

            $table->timestamp('created_at')->index();

            $table->index(['user_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION vigil_parlay_grades_append_only()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'parlay_grades is append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER parlay_grades_append_only
                BEFORE UPDATE OR DELETE ON parlay_grades
                FOR EACH ROW EXECUTE FUNCTION vigil_parlay_grades_append_only();
            SQL);
        }
    }
};
