<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A player's rating, on the 0-99 scale the sports games made legible.
 *
 * The scale is borrowed and the method is not. NBA 2K and Madden do not
 * publish their formulas, and claiming to reproduce them would be a
 * claim about somebody else's work. What this holds is a percentile: a
 * rating of 94 means the player's production sits in the 94th percentile
 * of others in his position, measured from box scores this database
 * already holds - 754,000 player lines across four leagues.
 *
 * That is a narrower claim than "94 overall" implies anywhere else, and
 * the columns are shaped so it cannot be read as more: `games` and
 * `measured_from` travel with every rating, because a 94 over eleven
 * games and a 94 over four hundred are different statements.
 *
 * Recomputed rather than accumulated. A rating is a function of the games
 * on file at the time, so the row is replaced when it is recalculated and
 * `as_of` says when that was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_ratings', function (Blueprint $table): void {
            $table->id();

            $table->string('sport', 16);
            $table->string('player_external_id', 64);
            $table->string('player_name');

            /*
             * The peer group the percentile was taken within.
             *
             * Comparing a centre's rebounds against a guard's would
             * produce a rating that measures position rather than
             * ability. For the NFL this is the stat group rather than a
             * position, because the box scores carry no position at all -
             * a player filed under `passing` is a quarterback by what he
             * did, which is the better definition anyway.
             */
            $table->string('peer_group', 32);

            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();

            // 0-99, the percentile within the peer group.
            $table->unsignedTinyInteger('overall');

            /*
             * The parts the overall was built from, each on the same
             * scale: scoring, playmaking, defence and so on, per sport.
             * Kept as a map because the vocabulary differs by sport and
             * flattening it into fixed columns would mean inventing a
             * shared one that fits nobody.
             */
            $table->json('components');

            /*
             * The sample, carried with the number. A rating without it is
             * a number pretending to be evidence, which is the rule the
             * record pages already follow.
             */
            $table->unsignedSmallInteger('games');
            $table->timestampTz('measured_from');
            $table->timestampTz('measured_to');

            $table->timestampTz('as_of')->index();
            $table->timestampTz('created_at');

            /*
             * One current rating per player per sport. Recomputation
             * replaces it rather than appending, so this is the key that
             * makes the upsert work.
             */
            $table->unique(['sport', 'player_external_id']);
            $table->index(['sport', 'peer_group', 'overall']);
            $table->index(['team_id', 'overall']);
        });

        if (DB::getDriverName() === 'pgsql') {
            /*
             * The scale is a percentile and cannot leave 0-99. A rating
             * of 140 would be a bug in the calculator, and the database
             * should refuse it rather than render it.
             */
            DB::statement('ALTER TABLE player_ratings ADD CONSTRAINT player_ratings_scale
                CHECK (overall >= 0 AND overall <= 99)');

            // A rating over zero games is not a rating.
            DB::statement('ALTER TABLE player_ratings ADD CONSTRAINT player_ratings_sample
                CHECK (games > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('player_ratings');
    }
};
