<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One player's line from one game.
 *
 * There is no players table and this does not add one. Players are
 * identified the way `injury_reports` already identifies them - by the
 * source's own id plus a name - because a player registry is a
 * maintenance burden that earns its keep only when something needs to
 * look a player up by identity, and nothing here does. What the engine
 * needs is "who was on the floor and what did they do", which is this
 * row.
 *
 * Not append-only, and upserted on (event_id, source, player_external_id).
 * A box score is corrected after the whistle, and the later version is
 * the right one - the same reason `team_game_stats` is not append-only.
 *
 * `stats` is jsonb under the source's own labels rather than columns. A
 * basketball line and a football line share almost nothing, and a table
 * holding both as columns would be two tables wearing one name. The
 * feature builder reads the handful of keys it understands per sport.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_game_stats', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('sport', 16);
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            /*
             * The team is nullable on purpose. A player line is worth
             * keeping even when the side cannot be resolved - it still
             * says what happened in the game - and dropping it would
             * lose the game's whole roster over one unmatched name.
             */
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();

            $table->string('player_external_id', 64);
            $table->string('player_name');
            $table->string('position', 16)->nullable();

            // Did they actually play. A dressed inactive is a real fact
            // and not the same as a zero line.
            $table->boolean('played')->default(true);

            /*
             * The group the source filed this line under: "passing",
             * "rushing", "receiving" for football. A sport that reports
             * one line per player gets 'all'.
             *
             * Not nullable, and that matters: it is part of the unique
             * index below, and in Postgres two NULLs do not conflict. A
             * nullable column here would let basketball - which has no
             * groups - insert the same player twice per game and the
             * constraint would allow it.
             */
            $table->string('stat_group', 32)->default('all');

            $table->jsonb('stats')->nullable();

            $table->string('source', 32);
            $table->timestampTz('as_of');
            $table->timestampsTz();

            /*
             * Scoped by stat group as well as player: football files the
             * same athlete under passing and rushing in the same game,
             * and those are two rows, not a conflict.
             */
            $table->unique(
                ['event_id', 'source', 'player_external_id', 'stat_group'],
                'player_game_stats_unique'
            );

            $table->index(['sport', 'player_external_id']);
            $table->index(['team_id', 'event_id']);
            $table->index(['event_id', 'stat_group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_game_stats');
    }
};
