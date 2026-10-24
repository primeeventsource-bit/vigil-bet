<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the percentage cap be off rather than only low.
 *
 * max_stake_pct was NOT NULL, so there was no way to say "no percentage
 * guard" - only "a very large one", which is a different statement. 100%
 * of bankroll is not no cap: it still scales with the balance, and it still
 * turns up in the explanation attached to a stake as the thing that sized
 * it. Null now means off, and nothing written so far changes.
 *
 * A column definition change rather than a row change, so the append-only
 * trigger on this table is untroubled: that guard fires on UPDATE and
 * DELETE of rows.
 *
 * Forward-only, and for the same reason as the stake bounds before it. Once
 * a row says "off" there is no percentage to put back, and the table cannot
 * be updated in place to invent one - so NOT NULL could not be restored
 * even if down() asked for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bankroll_settings', function (Blueprint $table) {
            $table->decimal('max_stake_pct', 5, 2)->nullable()->default(2.00)->change();
        });
    }

    public function down(): void
    {
        // Deliberately empty. See above: a nullable column is the only
        // state this table can be in once something has been saved with
        // the cap off, and failing here would make the whole batch
        // unrollable rather than making the data recoverable.
    }
};
