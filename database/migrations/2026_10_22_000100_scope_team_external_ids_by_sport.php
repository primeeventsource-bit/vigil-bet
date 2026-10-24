<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A source's team id is only unique inside one league.
 *
 * `team_external_ids` was unique on (source, external_id), which assumes
 * every source numbers teams globally. ESPN does not: it numbers them per
 * league, so espn:14 is a real and different team in the NHL, the NBA and
 * college basketball.
 *
 * The consequence in production was quiet and bad. Whichever league synced
 * first claimed the number, and every later league reading that id was
 * handed the first league's team - no duplicate row, no constraint
 * violation, nothing in any log. Sixteen NBA fixtures ended up holding NHL
 * and college teams, and the board cheerfully showed "UAB Blazers at Tampa
 * Bay Lightning" with a basketball total on it.
 *
 * Sport is denormalised onto the row rather than reached through the team,
 * because it has to take part in the unique index and a unique index
 * cannot span a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_external_ids', function (Blueprint $table) {
            $table->string('sport', 16)->nullable()->after('team_id');
        });

        // Every row has a team, and every team has a sport, so this fills
        // all of them. Written as one statement because both drivers
        // support a correlated subquery in UPDATE and chunking a reference
        // table of a few hundred rows would be ceremony.
        DB::statement(
            'UPDATE team_external_ids SET sport = ('
            .'SELECT sport FROM teams WHERE teams.id = team_external_ids.team_id'
            .') WHERE sport IS NULL'
        );

        // Anything still null has no team to inherit from, which the
        // foreign key should make impossible. Removed rather than left to
        // break the NOT NULL below.
        DB::table('team_external_ids')->whereNull('sport')->delete();

        Schema::table('team_external_ids', function (Blueprint $table) {
            $table->string('sport', 16)->nullable(false)->change();
            $table->dropUnique(['source', 'external_id']);
            $table->unique(['source', 'external_id', 'sport'], 'team_external_ids_source_id_sport_unique');
        });
    }

    public function down(): void
    {
        // Going back means one row per (source, external_id) again, so the
        // extras have to go or the old unique index cannot be rebuilt.
        // Lowest id wins, which is the row the old scheme would have kept.
        DB::statement(
            'DELETE FROM team_external_ids WHERE id NOT IN ('
            .'SELECT MIN(id) FROM team_external_ids GROUP BY source, external_id'
            .')'
        );

        Schema::table('team_external_ids', function (Blueprint $table) {
            $table->dropUnique('team_external_ids_source_id_sport_unique');
            $table->unique(['source', 'external_id']);
            $table->dropColumn('sport');
        });
    }
};
