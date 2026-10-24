<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A source's game id is only unique inside one league.
 *
 * Same flaw as team_external_ids carried, one table over. This was unique
 * on (source, external_id), which assumes a source numbers games globally.
 * ESPN does not: its event ids are per-league, so espn:401547417 is a real
 * and different game in more than one of them.
 *
 * It has not bitten yet - no ESPN event id in this database is currently
 * shared across sports - and that is the whole reason to fix it now rather
 * than after it does. The failure mode is the quiet one: the second league
 * to sync does not hit a constraint, it reads the first league's event and
 * files its lines, results and weather against a game from another sport.
 * There would be no duplicate row and nothing in any log.
 *
 * Sport is denormalised onto the row rather than reached through the event
 * because it has to take part in the unique index, and a unique index
 * cannot span a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_external_ids', function (Blueprint $table) {
            $table->string('sport', 16)->nullable()->after('event_id');
        });

        // Every row has an event and every event has a sport, so this fills
        // all of them. One statement because both drivers take a correlated
        // subquery in UPDATE, and the table is small.
        DB::statement(
            'UPDATE event_external_ids SET sport = ('
            .'SELECT sport FROM events WHERE events.id = event_external_ids.event_id'
            .') WHERE sport IS NULL'
        );

        // Anything still null has no event to inherit from, which the
        // cascading foreign key should make impossible. Removed rather than
        // left to break the NOT NULL below.
        DB::table('event_external_ids')->whereNull('sport')->delete();

        Schema::table('event_external_ids', function (Blueprint $table) {
            $table->string('sport', 16)->nullable(false)->change();
            $table->dropUnique(['source', 'external_id']);
            $table->unique(['source', 'external_id', 'sport'], 'event_external_ids_source_id_sport_unique');
        });
    }

    public function down(): void
    {
        // Back to one row per (source, external_id), so the extras have to
        // go before the old index can be rebuilt. Lowest id wins, which is
        // the row the old scheme would have kept.
        DB::statement(
            'DELETE FROM event_external_ids WHERE id NOT IN ('
            .'SELECT MIN(id) FROM event_external_ids GROUP BY source, external_id'
            .')'
        );

        Schema::table('event_external_ids', function (Blueprint $table) {
            $table->dropUnique('event_external_ids_source_id_sport_unique');
            $table->unique(['source', 'external_id']);
            $table->dropColumn('sport');
        });
    }
};
