<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which Reads a customer has actually seen, and on which day.
 *
 * This is how "one Vigil Read a day" on the free plan is enforced. A
 * counter would have been simpler and wrong: re-opening a Read a customer
 * has already read must not cost them a second one, and a counter cannot
 * tell the difference between reading two Reads and reading one twice.
 *
 * So the unique key is (user, read, day). The day's allowance is the
 * number of distinct Reads seen, and going back to one already seen is
 * free for ever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('read_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vigil_read_id')->constrained('vigil_reads')->cascadeOnDelete();

            // The date, not a timestamp. The allowance is per day and
            // comparing timestamps to find "today" in the customer's
            // timezone at read time is how off-by-one bugs get in.
            $table->date('viewed_on');

            $table->timestamp('created_at');

            $table->unique(['user_id', 'vigil_read_id', 'viewed_on']);
            $table->index(['user_id', 'viewed_on']);
        });

        Schema::table('ai_usage', function (Blueprint $table): void {
            /*
             * Who the call was made for, where it was made for somebody
             * in particular. Null for the nightly board backfill, which
             * is made for everybody.
             *
             * Needed to enforce a per-tier generation limit, and useful
             * for the thing that comes after it: knowing which customers
             * the AI bill is actually spent on.
             */
            $table->foreignId('user_id')->nullable()->after('purpose')
                ->constrained()->nullOnDelete();
        });
    }
};
