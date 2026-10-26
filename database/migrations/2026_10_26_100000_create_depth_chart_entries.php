<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who is first choice, and who is fourth.
 *
 * The rating has never seen a team sheet, so an absence reaches the
 * decision only as a count of bodies - and a head count says a first
 * quarterback and a fourth are the same event. Weighting by position
 * fixed half of that. This fixes the other half: a position is not what
 * makes a player matter, being first choice at it is.
 *
 * It also answers the question weighting alone gets wrong. Philadelphia
 * on 26 October had Jalen Hurts out since the 11th, by which point ESPN
 * had him fourth on its own chart and Tanner McKee had started the game
 * in between. An absence the team has already played around is priced
 * into the market and mostly into the rating; treating it like a
 * quarterback lost this morning would shrink a number that nothing new
 * has happened to.
 *
 * APPEND ONLY, like injury_reports and for the same reason: a chart is a
 * point-in-time observation, and the only honest way to ask what was
 * known before kickoff is to keep what was said and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depth_chart_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('sport', 16);
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            /*
             * The provider's athlete id, which is the join that makes this
             * cheap: player_game_stats already stores ESPN's id, so a
             * chart can be read without resolving a single name.
             */
            $table->string('player_external_id', 64);
            $table->string('player_name')->nullable();

            // As the chart labels it: qb, rb, wr. Not the player's listed
            // position, which can differ.
            $table->string('position', 16);

            // The formation the chart belongs to, kept because a player
            // can be first choice in one and absent from another.
            $table->string('formation', 48)->nullable();

            // 1 is first choice. The provider counts from one.
            $table->unsignedSmallInteger('rank');

            $table->string('source', 32);
            $table->timestampTz('as_of');
            $table->timestampTz('created_at')->nullable();

            $table->index(['sport', 'team_id', 'as_of'], 'depth_chart_team_idx');
            $table->index(['sport', 'player_external_id', 'as_of'], 'depth_chart_player_idx');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        /*
         * The same guard every other observation table carries. The
         * function was created by the integrity guards migration and is
         * reused rather than redefined, so there is one definition of
         * what append-only means.
         */
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER depth_chart_entries_append_only
            BEFORE UPDATE OR DELETE ON depth_chart_entries
            FOR EACH ROW EXECUTE FUNCTION edge_deny_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('depth_chart_entries');
    }
};
